<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2025 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\PanopticonConnector\Model;

defined('_JEXEC') || die;

use Akeeba\PanopticonConnector\Controller\Mixit\ElementToExtensionIdTrait;
use Exception;
use JModelLegacy;
use JUpdater;
use stdClass;
use Throwable;

class ExtensionsModel extends \JModelList
{
	use ElementToExtensionIdTrait;

	public function __construct($config = [])
	{
		$config['filter_fields'] = $config['filter_fields'] ?? [
			'updatable',
			'protected',
			'id',
			'core',
		];

		parent::__construct($config);
	}

	protected function getListQuery()
	{
		$protected = $this->getState('filter.protected', 0);
		$protected = ($protected !== '' && is_numeric($protected)) ? intval($protected) : null;

		$updateRelevantEIDs = $this->getPossiblyNaughtExtensionIDs();
		\JArrayHelper::toInteger($updateRelevantEIDs);

		if (empty($updateRelevantEIDs))
		{
			$updateRelevantEIDs = [-1];
		}

		$db    = $this->getDbo();
		$query = $db->getQuery(true)
			->select(
				[
					$db->quoteName('e.extension_id', 'id'),
					$db->quoteName('e') . '.*',
					$db->quoteName('u.version', 'new_version'),
					$db->quoteName('u.detailsurl'),
					$db->quoteName('u.infourl'),
					'NULL AS ' . $db->quoteName('changelogurl'),
				]
			)
			->from($db->quoteName('#__extensions', 'e'))
			->join(
				'LEFT OUTER',
				$db->quoteName('#__updates', 'u') . ' ON(' .
				$db->quoteName('u.extension_id') . ' = ' . $db->quoteName('e.extension_id') . ')'
			)
			->where(
				[
					$db->quoteName('e.package_id') . ' = 0' .
					' OR ' .
					$db->quoteName('e.extension_id') . ' IN(' . implode(
						',', $updateRelevantEIDs
					) . ')',
				]
			);

		if (is_int($protected) && $protected >= 0)
		{
			$protected = boolval($protected) ? 1 : 0;
			$query->where(
				$db->quoteName('protected') . ' = ' . (int) $protected
			);
		}

		$updatable = $this->getState('filter.updatable', '');

		if ($updatable !== '' && $updatable)
		{
			$query->where($db->quoteName('u.version') . ' IS NOT NULL');
		}

		$eid = $this->getState('filter.id', '');
		$eid = ($eid !== '' && is_numeric($eid)) ? intval($eid) : null;

		if (is_int($eid) && $eid > 0)
		{
			$query->where($db->quoteName('e.extension_id') . ' = ' . (int) $eid);
		}

		return $query;
	}

	protected function _getList($query, $limitstart = 0, $limit = 0)
	{
		// Force-reload the update before listing extensions?
		$this->refreshUpdateInformation(
			$this->getState('filter.force', false), $this->getState('filter.timeout', null)
		);

		// Get all items from the database. We deliberately don't apply any limits just yet.
		$items = parent::_getList($query);

		// Pull information from the manifest cache
		$items = array_map(
			function (object $item): object {
				try
				{
					$manifestCache = @json_decode($item->manifest_cache ?? '{}') ?? new stdClass();
				}
				catch (Exception $e)
				{
					$manifestCache = new stdClass();
				}

				$item->author      = $manifestCache->author ?? '';
				$item->authorUrl   = $manifestCache->authorUrl ?? '';
				$item->authorEmail = $manifestCache->authorEmail ?? '';
				$item->version     = $manifestCache->version ?? '0.0.0';
				$item->description = $manifestCache->description ?? '';

				return $item;
			},
			$items
		);

		// Apply the filter.core filter, if requested
		$coreFilter = $this->getState('filter.core', '');
		$coreFilter = ($coreFilter !== '' && is_numeric($coreFilter)) ? intval($coreFilter) === 1 : null;

		if (!is_null($coreFilter))
		{
			$items = array_filter(
				$items,
				function ($item) use ($coreFilter) {
					return !$coreFilter xor (strpos($item->authorUrl, 'www.joomla.org') !== false);
				}
			);
		}

		// Translate some information
		$jLang = \JFactory::getApplication()->getLanguage();
		// -- Load the com_installer language files; they are used below
		$jLang->load('com_installer', JPATH_ADMINISTRATOR, null);

		$items = array_map(
			function (object $item) use ($jLang): object {
				// Translate the client, extension type, and folder
				$item->client_translated = \JText::_(
					[
						0 => 'JSITE',
						1 => 'JADMINISTRATOR',
						3 => 'JAPI',
					][$item->client_id] ?? 'JSITE'
				);
				$item->type_translated   = \JText::_('COM_INSTALLER_TYPE_' . strtoupper($item->type));
				$item->folder_translated = @$item->folder
					? $item->folder
					: \JText::_(
						'COM_INSTALLER_TYPE_NONAPPLICABLE'
					);

				// Load an extension's language files (if applicable)
				$path = $item->client_id ? JPATH_ADMINISTRATOR : JPATH_SITE;

				switch ($item->type)
				{
					case 'component':
						$extension = $item->element;
						$source    = JPATH_ADMINISTRATOR . '/components/' . $extension;
						$jLang->load("$extension.sys", JPATH_ADMINISTRATOR) || $jLang->load("$extension.sys", $source);
						break;

					case 'file':
						$extension = 'files_' . $item->element;
						$jLang->load("$extension.sys", JPATH_SITE);
						break;

					case 'library':
						$parts     = explode('/', $item->element);
						$vendor    = (isset($parts[1]) ? $parts[0] : null);
						$extension = 'lib_' . ($vendor ? implode('_', $parts) : $item->element);

						if (!$jLang->load("$extension.sys", $path))
						{
							$source = $path . '/libraries/' . ($vendor ? $vendor . '/' . $parts[1] : $item->element);
							$jLang->load("$extension.sys", $source);
						}
						break;

					case 'module':
						$extension = $item->element;
						$source    = $path . '/modules/' . $extension;
						$jLang->load("$extension.sys", $path) || $jLang->load("$extension.sys", $source);
						break;

					case 'plugin':
						$extension = 'plg_' . $item->folder . '_' . $item->element;
						$source    = JPATH_PLUGINS . '/' . $item->folder . '/' . $item->element;
						$jLang->load("$extension.sys", JPATH_ADMINISTRATOR) || $jLang->load("$extension.sys", $source);
						break;

					case 'template':
						$extension = 'tpl_' . $item->element;
						$source    = $path . '/templates/' . $item->element;
						$jLang->load("$extension.sys", $path) || $jLang->load("$extension.sys", $source);
						break;

					case 'package':
					default:
						$extension = $item->element;
						$jLang->load("$extension.sys", JPATH_SITE);
						break;
				}

				// Translate the extension name, if applicable
				$item->name = \JText::_($item->name);

				// Translate the description, if applicable
				$item->description = empty($item->description) ? '' : \JText::_($item->description);

				return $item;
			},
			$items
		);

		// Apply limits
		$limitstart = $limitstart ?: 0;
		$limit      = $limit ?: 0;

		if ($limitstart !== 0 && $limit === 0)
		{
			$items = array_slice($items, $limitstart);
		}
		elseif ($limitstart !== 0 && $limit !== 0)
		{
			$items = array_slice($items, $limitstart, $limit);
		}

		// Add Update Site information for each extension
		$extensionIDs = array_unique(
			array_merge(
				array_map(
					function ($item) {
						return $item->extension_id;
					},
					$items
				),
				array_filter(
					array_map(
						function ($e) {
							return $e->package_id;
						},
						$items
					)
				)
			)
		);

		$updateSites       = empty($items) ? [] : $this->getUpdateSitesForExtensions($extensionIDs);
		$naughtyExtensions = [];


		$items = array_map(
			function (object $item) use ($updateSites, &$naughtyExtensions): object {
				$ownUpdateSite     = $updateSites[$item->extension_id] ?? null;
				$parentUpdateSite  = empty($item->package_id)
					? []
					: ($updateSites[$item->package_id] ?? []);
				$item->updatesites = $ownUpdateSite ?? $parentUpdateSite;

				if (empty($ownUpdateSite) && !empty($parentUpdateSite))
				{
					$naughtyExtensions[$item->package_id]   = 'parent';
					$naughtyExtensions[$item->extension_id] = 'child';
				}

				$item->extra_query = array_reduce(
					$item->updatesites,
					function (string $carry, object $updateSite) {
						return $carry ?: $updateSite->extra_query;
					},
					''
				);

				// Note: Joomla 3 does not know about Download Keys

				return $item;
			},
			$items
		);

		// Mark items as naughty or nice
		$items = array_map(
			function ($item) use ($naughtyExtensions) {
				$item->naughtyUpdates = $naughtyExtensions[$item->id] ?? null;

				return $item;
			},
			$items
		);

		return $items;
	}

	protected function _getListCount($query)
	{
		return count($this->_getList($query, 0, 0));
	}

	private function getUpdateSitesForExtensions(array $eids): array
	{
		$db   = $this->getDbo();
		$eids = array_filter(array_map('intval', $eids));

		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__update_sites_extensions'))
			->where($db->quoteName('extension_id') . ' IN(' . implode(',', $eids) . ')');

		try
		{
			$temp = $db->setQuery($query)->loadObjectList() ?: [];
		}
		catch (Exception $e)
		{
			return [];
		}

		$updateSitesPerEid = [];
		$updateSiteIDs     = [];

		foreach ($temp as $item)
		{
			$updateSitesPerEid[$item->extension_id]   = $updateSitesPerEid[$item->extension_id] ?? [];
			$updateSitesPerEid[$item->extension_id][] = $item->update_site_id;
			$updateSiteIDs[]                          = $item->update_site_id;
		}

		$updateSiteIDs = array_unique(array_filter(array_map('intval', $updateSiteIDs)));

		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__update_sites'))
			->where($db->quoteName('update_site_id') . ' IN(' . implode(',', $updateSiteIDs) . ')');

		try
		{
			$temp = $db->setQuery($query)->loadObjectList('update_site_id') ?: [];
		}
		catch (Exception $e)
		{
			return [];
		}

		$ret = [];

		foreach ($updateSitesPerEid as $eid => $usids)
		{
			$ret[$eid] = array_filter(
				array_map(
					function (int $usid) use ($temp) {
						return $temp[$usid] ?? null;
					},
					$usids
				)
			);
		}

		$ret = array_filter($ret);

		return $ret;
	}

	/**
	 * Returns all extension IDs which receive updates, even though they might not have an update site.
	 *
	 * Some updates have an asinine —not to put too fine a point on it— way of (ab)using Joomla's extensions update
	 * system. Let's take "What? Nothing!" as an example (sorry, Peter!).
	 *
	 * You download a package extension which consists of two extensions: a library extension and a plugin extension.
	 * It creates an update site for the package extension. However, the contents of the update site (the XML
	 * downloaded) says it's an update for the **plugin** extension (which does not have an update site in Joomla!, and
	 * is a sub-extension of the package, therefore it should not be receiving updates on its own). And yet, the update
	 * ZIP package you download is a **package** extension.
	 *
	 * The thing is, since the XML of the update site tells Joomla that a _plugin_ update is available, Joomla reports
	 * that an extension to the _plugin_ is available, even though it will eventually install a package update (it
	 * cannot know that at this point).
	 *
	 * Quite the clusterfuck.
	 *
	 * This is problematic for many reasons.
	 *
	 * First of all, Joomla! will create a _plugin_ installation adapter to install the update ZIP file, even though the
	 * update ZIp file is in fact a package update. This seems to be half-fixed on Joomla 4 so, okay, it won't break
	 * stuff very badly.
	 *
	 * The second problem is that the Joomla! Update Pre-Update Check will **ALWAYS** report the **PACKAGE** extension
	 * as incompatible with the next version of Joomla because the package extension does not have any update
	 * information. Remember, the update site's XML data claims that only the plugin receives updates but since the
	 * plugin is a sub-extension of the package it's not listed separately. Therefore, the clients of this extensions
	 * developer will always be told a lie and will grow not to trust the pre-update check.
	 *
	 * The final problem is that we normally cannot know that the **PLUGIN** shows as the target of available updates
	 * as it does not have an update site attached to it AND it's part of a package.
	 *
	 * The first two problems are the 3PD's problems. Not our monkey, not our circus.
	 *
	 * The third problem, though, becomes our problem as we cannot display the available update, therefore we cannot do
	 * automatic updates. As a result we need to exploit Joomla's idiotic way of handling updates in the database to get
	 * the extension IDs of these problematic extensions just so we can include them in the list of extensions which can
	 * receive updates.
	 *
	 * The solution is pretty much part of the Dark Arts of Joomla! Core. DO NOT TRY THIS AT HOME. RISK OF SERIOUS BRAIN
	 * INJURY OR DEATH.
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function getPossiblyNaughtExtensionIDs(): array
	{
		$db    = $this->getDbo();
		$query = $db->getQuery(true)
			->select(
				'DISTINCT ' . $db->quoteName('use.update_site_id')
			)
			->from($db->quoteName('#__updates', 'u'))
			->innerJoin(
				$db->quoteName('#__update_sites_extensions', 'use') . ' ON ' .
				$db->quoteName('use.update_site_id') . ' = ' . $db->quoteName('u.update_site_id')
			);

		$query2 = $db->getQuery(true)
			->select(
				'DISTINCT ' . $db->quoteName('e.extension_id')
			)
			->from($db->quoteName('#__updates', 'u'))
			->innerJoin(
				$db->quoteName('#__extensions', 'e') . ' ON ' .
				$db->quoteName('e.element') . ' = ' . $db->quoteName('u.element') .
				' AND ' .
				$db->quoteName('e.type') . ' = ' . $db->quoteName('u.type') .
				' AND ' .
				$db->quoteName('e.folder') . ' = ' . $db->quoteName('u.folder') .
				' AND ' .
				$db->quoteName('e.client_id') . ' = ' . $db->quoteName('u.client_id')
			);

		$query->union($query2);

		return $db->setQuery($query)->loadColumn() ?: [];
	}

	private function getParentPackageExtension(object $item): ?object
	{
		$pid = $item->package_id;

		if (empty($pid))
		{
			return null;
		}

		if (isset($this->cacheForPackageExtensions[$pid]))
		{
			return $this->cacheForPackageExtensions[$pid];
		}

		$db    = $this->getDbo();
		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('extension_id') . ' = ' . (int) $pid);

		return $db->setQuery($query)->loadObject() ?? null;
	}

	/**
	 * Refreshes the update information.
	 *
	 * @param   bool         $forceReload   Whether to force-reload the update before listing extensions.
	 * @param   int|null     $cacheTimeout  The caching duration for updates in seconds. Default is null.
	 * @param   string|null  $minStability  The minimum stability level for updates. Default is null.
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	private function refreshUpdateInformation(
		bool $forceReload, ?int $cacheTimeout = null, ?string $minStability = null
	): void
	{
		// Get the updates caching duration and minimum stability
		$params       = \JComponentHelper::getComponent('com_installer')->getParams();
		$cacheTimeout = $cacheTimeout ?? 3600 * ((int) $params->get('cachetimeout', 6));
		$minStability = $minStability ?? (int) $params->get('minimum_stability', JUpdater::STABILITY_STABLE);

		// Make sure the parameters have values and that they are within bounds.
		$cacheTimeout = (int) ($cacheTimeout ?: 6);
		$minStability = (int) ($minStability ?: JUpdater::STABILITY_STABLE);

		if ($cacheTimeout <= 0)
		{
			$cacheTimeout = 1;
		}
		elseif ($cacheTimeout >= 24)
		{
			$cacheTimeout = 24;
		}

		if (!in_array($minStability, [
			JUpdater::STABILITY_DEV, JUpdater::STABILITY_ALPHA, JUpdater::STABILITY_BETA, JUpdater::STABILITY_RC,
			JUpdater::STABILITY_STABLE
		])) {
			$minStability = JUpdater::STABILITY_STABLE;
		}

		// Force-reload the update before listing extensions, if asked to do so.
		if ($forceReload)
		{

			try
			{
				if (!class_exists(\InstallerModelUpdate::class))
				{
					require_once JPATH_ADMINISTRATOR . '/components/com_installer/models/update.php';
				}

				/** @var \InstallerModelUpdate $model */
				JModelLegacy::getInstance(
					'Update', 'InstallerModel', ['ignore_request' => true]
				)->purge();
			}
			catch (Throwable $e)
			{
				// Ignore any internal / database errors here.
			}
		}

		// Ask Joomla! to refresh its update information.
		try
		{
			JUpdater::getInstance()->findUpdates(0, $cacheTimeout, $minStability);
		}
		catch (Throwable $e)
		{
			// Just in case…
		}
	}
}