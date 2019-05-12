<?php
namespace Netresearch\Composer\Patches\Downloader;

/*                                                                        *
 * This script belongs to the Composer-TYPO3-Installer package            *
 * (c) 2014 Netresearch GmbH & Co. KG                                     *
 * This copyright notice MUST APPEAR in all copies of the script!         *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License, either version 3   *
 * of the License, or (at your option) any later version.                 *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use Composer\Util\RemoteFilesystem;

/**
 * Downloader, which uses the composer RemoteFilesystem
 */
class Composer implements DownloaderInterface {
	/**
	 * @var RemoteFilesystem 
	 */
	protected $remoteFileSystem;

    /**
     * @var array
     */
	protected $downloaderCache;

	/**
	 * Construct the RFS
	 * 
	 * @param \Composer\IO\IOInterface $io
	 */
	public function __construct(\Composer\IO\IOInterface $io) {
		$this->remoteFileSystem = new RemoteFilesystem($io);
		$this->downloaderCache = array();
	}

	/**
	 * Get the origin URL required by composer rfs
	 * 
	 * @param string $url
	 * @return string
	 */
	protected function getOriginUrl($url) {
		return parse_url($url, PHP_URL_HOST);
	}

	/**
	 * Download the file and return its contents
	 * 
	 * @param string $url The URL from where to download
	 * @return string Contents of the URL
	 */
	public function getContents($url) {
		return $this->remoteFileSystem->getContents($this->getOriginUrl($url), $url, false);
	}

	/**
	 * Download file and decode the JSON string to PHP object
	 *
	 * @param string $json
	 * @return array|stdClass
	 */
	public function getJson($url) {
	    if (($cached = $this->fromCache($url)) !== null) {
	        return $cached;
        }

		$json = new \Composer\Json\JsonFile($url, $this->remoteFileSystem);
	    $data = $json->read();
		$this->toCache($url, $data);
		return $data;
	}

    /**
     * @param string $url
     * @return mixed|null
     */
    protected function fromCache($url)
    {
        $cacheKey = $this->getCacheKey($url);

        if (array_key_exists($cacheKey, $this->downloaderCache)) {
            return $this->downloaderCache[$cacheKey];
        }

        return null;
	}

    /**
     * @param string $url
     * @param mixed $json
     * @return void
     */
    protected function toCache($url, $json)
    {
        $this->downloaderCache[$this->getCacheKey($url)] = $json;
	}

    /**
     * @param string $url
     * @return string
     */
    protected function getCacheKey($url)
    {
        return hash("md5", $url);
	}
}
?>