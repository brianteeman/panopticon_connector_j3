<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\PanopticonConnector\Controller;

defined('_JEXEC') || die;

use Exception;
use JFactory;
use JHttpFactory;
use JInput;
use JInstaller;
use RuntimeException;

class ExtensionInstall extends AbstractController
{
	/**
	 * Invokes the method to handle package installation via HTTP methods.
	 *
	 * @param   JInput  $input  The input object containing request data.
	 *
	 * @return  object  The result of the installation process, containing status and messages.
	 *
	 * @throws  RuntimeException  If the URL or filename is invalid, required parameters are missing,
	 *                             or the upload/download/install process fails.
	 * @throws  Exception         If an unexpected error occurs during processing.
	 * @since   1.1.0
	 */
	public function __invoke(JInput $input): object
	{
		$app = JFactory::getApplication();

		// Check if remote extension installation is allowed
		$plugin       = \JPluginHelper::getPlugin('system', 'panopticon');
		$pluginParams = new \Joomla\Registry\Registry($plugin->params ?? '{}');

		if (!$pluginParams->get('allow_remote_install', 1))
		{
			throw new RuntimeException('Remote extension installation is disabled on this site.', 403);
		}

		// Load com_installer language
		$app->getLanguage()->load('com_installer', JPATH_ADMINISTRATOR);

		$method = strtoupper($input->getMethod());
		$packageFile = null;

		try
		{
			// Get the temporary directory
			$config = JFactory::getConfig();
			$tmpPath = $config->get('tmp_path');

			if ($method === 'POST')
			{
				// Download from URL
				$url = $input->post->getString('url', '');

				if (empty($url))
				{
					throw new RuntimeException('URL parameter is required', 400);
				}

				// Validate URL
				if (!filter_var($url, FILTER_VALIDATE_URL))
				{
					throw new RuntimeException('Invalid URL provided', 400);
				}

				// Download the file
				$packageFile = $this->downloadPackage($url, $tmpPath);
			}
			elseif ($method === 'PUT')
			{
				// Upload binary data
				$filename = $input->get->getString('filename', '');

				if (empty($filename))
				{
					throw new RuntimeException('Filename parameter is required', 400);
				}

				// Sanitise filename - remove dots (except the last one for extension), backslashes, and forward slashes
				$filename = $this->sanitizeFilename($filename);

				// Get the raw input data
				$rawData = file_get_contents('php://input');

				if (empty($rawData))
				{
					throw new RuntimeException('No file data received', 400);
				}

				// Save the uploaded file
				$packageFile = $tmpPath . '/' . $filename;

				if (file_put_contents($packageFile, $rawData) === false)
				{
					throw new RuntimeException('Failed to save uploaded file', 500);
				}
			}
			else
			{
				throw new RuntimeException('Method not allowed. Use POST or PUT.', 405);
			}

			// Install the package
			$result = $this->installPackage($packageFile);

			return $this->asSingleItem('extension_install', [
				'id'       => 0,
				'status'   => $result,
				'messages' => $app->getMessageQueue(true),
			]);
		}
		catch (Exception $e)
		{
			// Clean up the package file on error
			if ($packageFile && file_exists($packageFile))
			{
				@unlink($packageFile);
			}

			throw $e;
		}
		finally
		{
			// Always clean up the package file
			if ($packageFile && file_exists($packageFile))
			{
				@unlink($packageFile);
			}
		}
	}

	/**
	 * Download a package from a URL
	 *
	 * @param   string  $url      The URL to download from
	 * @param   string  $tmpPath  The temporary directory path
	 *
	 * @return  string  The path to the downloaded file
	 * @throws  RuntimeException
	 * @since   1.1.0
	 */
	private function downloadPackage(string $url, string $tmpPath): string
	{
		// Generate a unique filename
		$filename = 'panopticon_install_' . md5($url . microtime(true)) . '.zip';
		$packageFile = $tmpPath . '/' . $filename;

		// Download the file
		$http = JHttpFactory::getHttp();

		try
		{
			$response = $http->get($url);

			if ($response->code !== 200)
			{
				throw new RuntimeException('Failed to download package: HTTP ' . $response->code, 500);
			}

			if (file_put_contents($packageFile, $response->body) === false)
			{
				throw new RuntimeException('Failed to save downloaded file', 500);
			}
		}
		catch (Exception $e)
		{
			if (file_exists($packageFile))
			{
				@unlink($packageFile);
			}

			throw new RuntimeException('Download failed: ' . $e->getMessage(), 500);
		}

		return $packageFile;
	}

	/**
	 * Sanitise the filename by removing dangerous characters
	 *
	 * @param   string  $filename  The filename to sanitise
	 *
	 * @return  string  The sanitised filename
	 * @since   1.1.0
	 */
	private function sanitizeFilename(string $filename): string
	{
		// Get the file extension
		$parts = explode('.', $filename);
		$extension = '';

		if (count($parts) > 1)
		{
			$extension = '.' . array_pop($parts);
		}

		// Remove all dots, backslashes, and forward slashes from the basename
		$basename = implode('', $parts);
		$basename = str_replace(['/', '\\', '.'], '', $basename);

		// If the basename is empty, generate a random one
		if (empty($basename))
		{
			$basename = 'panopticon_install_' . md5(microtime(true));
		}

		// Default to .zip if no extension
		if (empty($extension))
		{
			$extension = '.zip';
		}

		return $basename . $extension;
	}

	/**
	 * Install a package file
	 *
	 * @param   string  $packageFile  The path to the package file
	 *
	 * @return  bool  True on success, false on failure
	 * @throws  RuntimeException|Exception
	 * @since   1.1.0
	 */
	private function installPackage(string $packageFile): bool
	{
		if (!file_exists($packageFile))
		{
			throw new RuntimeException('Package file not found', 500);
		}

		// Unpack the package archive into a temporary directory
		$package = \JInstallerHelper::unpack($packageFile);

		if (empty($package) || empty($package['dir']))
		{
			throw new RuntimeException('Failed to unpack the extension package.', 500);
		}

		$extractDir = $package['dir'];

		try
		{
			// Get the installer
			$installer = JInstaller::getInstance();

			// Attempt to install from the extracted directory
			$result = $installer->install($extractDir);

			if (!$result)
			{
				$app = JFactory::getApplication();
				$messages = $app->getMessageQueue();
				$errorMsg = 'Installation failed';

				// Try to get a more specific error message
				if (!empty($messages))
				{
					$lastMessage = end($messages);
					if (is_array($lastMessage) && isset($lastMessage['message']))
					{
						$errorMsg .= ': ' . $lastMessage['message'];
					}
				}

				throw new RuntimeException($errorMsg, 500);
			}

			return true;
		}
		finally
		{
			// Clean up extracted directory
			\JInstallerHelper::cleanupInstall($packageFile, $extractDir);
		}
	}
}
