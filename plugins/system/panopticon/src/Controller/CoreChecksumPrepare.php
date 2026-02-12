<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\PanopticonConnector\Controller;

defined('_JEXEC') || die;

class CoreChecksumPrepare extends AbstractController
{
	public function __invoke(\JInput $input): object
	{
		$version = JVERSION;
		$url     = "https://getpanopticon.com/checksums/joomla/{$version}/sha256_squash.json.gz";

		$http     = \JHttpFactory::getHttp();
		$response = $http->get($url);

		if ($response->code !== 200)
		{
			throw new \RuntimeException(
				"Could not download checksums from $url (HTTP " . $response->code . ")"
			);
		}

		$body = $response->body;

		$gzContent = @gzdecode($body);

		if ($gzContent === false)
		{
			throw new \RuntimeException("Failed to decompress checksums file");
		}

		$checksums = json_decode($gzContent, true);

		if (json_last_error() !== JSON_ERROR_NONE)
		{
			throw new \RuntimeException("Failed to parse checksums JSON: " . json_last_error_msg());
		}

		$db = \JFactory::getDbo();

		// Create the table if it doesn't exist
		$db->setQuery(
			'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__panopticon_coresums') . ' ('
			. $db->quoteName('id') . ' INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
			. $db->quoteName('path') . ' VARCHAR(1024) NOT NULL, '
			. $db->quoteName('checksum') . ' VARCHAR(128) NOT NULL'
			. ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
		)->execute();

		$db->truncateTable('#__panopticon_coresums');

		$paths     = array_keys($checksums);
		$total     = count($paths);
		$batchSize = 100;

		for ($i = 0; $i < $total; $i += $batchSize)
		{
			$query = $db->getQuery(true)
				->insert($db->quoteName('#__panopticon_coresums'))
				->columns([
					$db->quoteName('path'),
					$db->quoteName('checksum'),
				]);

			$batch = array_slice($paths, $i, $batchSize);

			foreach ($batch as $path)
			{
				$query->values($db->quote($path) . ', ' . $db->quote($checksums[$path]));
			}

			$db->setQuery($query)->execute();
		}

		return (object) ['done' => true];
	}
}
