<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\PanopticonConnector\Controller;

defined('_JEXEC') || die;

class CoreChecksumStep extends AbstractController
{
	public function __invoke(\JInput $input): object
	{
		$step = $input->getInt('step', 0);
		$db   = \JFactory::getDbo();

		$startTime    = microtime(true);
		$invalidFiles = [];
		$last_id      = $step;
		$done         = false;

		while (true)
		{
			$query = $db->getQuery(true)
				->select('*')
				->from($db->quoteName('#__panopticon_coresums'))
				->where($db->quoteName('id') . ' > ' . (int) $last_id)
				->order($db->quoteName('id') . ' ASC');
			$db->setQuery($query, 0, 250);
			$rows = $db->loadObjectList();

			if (empty($rows))
			{
				$done = true;
				break;
			}

			foreach ($rows as $row)
			{
				$path    = $row->path;
				$last_id = $row->id;

				// Skip files in the installation directory; they are removed after installation
				if (strpos($path, 'installation/') === 0)
				{
					continue;
				}

				$expectedChecksum = $row->checksum;
				$fullPath         = JPATH_ROOT . '/' . $path;
				$actualChecksum   = '';

				if (@is_file($fullPath))
				{
					$content = @file_get_contents($fullPath);

					if ($content === false)
					{
						continue;
					}

					$content        = preg_replace('#[\n\r\t\s\v]+#ms', ' ', $content);
					$actualChecksum = hash('sha256', $content);
				}

				if ($actualChecksum !== $expectedChecksum)
				{
					$invalidFiles[] = $path;
				}
			}

			if ((microtime(true) - $startTime) >= 2.0)
			{
				break;
			}
		}

		return (object) [
			'done'         => $done,
			'last_id'      => (int) $last_id,
			'invalidFiles' => $invalidFiles,
		];
	}
}
