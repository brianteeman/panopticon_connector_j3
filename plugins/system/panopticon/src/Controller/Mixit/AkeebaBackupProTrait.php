<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\PanopticonConnector\Controller\Mixit;

defined('_JEXEC') || die;

trait AkeebaBackupProTrait
{
	private ?bool $isAkeebaBackupProCached = null;

	private function isAkeebaBackupPro(): bool
	{
		if ($this->isAkeebaBackupProCached !== null)
		{
			return $this->isAkeebaBackupProCached;
		}

		if (!\JComponentHelper::isEnabled('com_akeeba'))
		{
			return $this->isAkeebaBackupProCached = false;
		}

		$versionFile = JPATH_ADMINISTRATOR . '/components/com_akeeba/version.php';

		if (@is_file($versionFile) && @is_readable($versionFile))
		{
			require_once $versionFile;
		}

		return $this->isAkeebaBackupProCached = defined('AKEEBA_PRO') && boolval(AKEEBA_PRO);
	}
}
