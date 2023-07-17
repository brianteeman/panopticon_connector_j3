<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2023 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\PanopticonConnector\Controller;

use Akeeba\PanopticonConnector\Model\CoreUpdateModel;
use Joomla\CMS\Factory;

defined('_JEXEC') || die;

class CoreUpdateActivate extends AbstractController
{
	public function __invoke(\JInput $input): object
	{
		$basename = $input->get('basename', null, 'raw');

		if (strpos($basename, DIRECTORY_SEPARATOR) !== false || strpos($basename, '/') !== false)
		{
			$basename = basename($basename);
		}

		/** @var CoreUpdateModel $model */
		$model = new CoreUpdateModel(['ignore_request' => true]);

		if (!$model->createRestorationFile($basename))
		{
			throw new \RuntimeException('Cannot create the administrator/components/com_joomlaupdate/restoration.php file.');
		}

		// Get the update package location
		$updateInfo = $model->getUpdateInformation();
		$packageURL = $updateInfo['object']->downloadurl->_data;
		$basename   = basename($packageURL);

		// Get the package name.
		$tempdir = Factory::getConfig()->get('tmp_path');
		$file    = $tempdir . '/' . $basename;

		$app = Factory::getApplication();

		$result = (object) [
			'id'       => 0,
			'password' => $app->getUserState('com_joomlaupdate.password'),
			'filesize' => $app->getUserState('com_joomlaupdate.filesize'),
			'file'     => $file,
		];

		return $this->asSingleItem('coreupdateactivate', $result);
	}
}