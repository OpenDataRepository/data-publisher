<?php

/**
 * Extracted verbatim (apart from the namespace) from the abandoned dterranova/crypto-bundle so ODR
 * keeps the EXACT same chunked file-encryption format -- existing encrypted files must remain
 * decryptable.
 */

namespace ODR\AdminBundle\Component\Crypto;

use ODR\AdminBundle\Component\Crypto\CipherInterface;

class CryptoAdapter {
	/**
	 * @var \ODR\AdminBundle\Component\Crypto\CipherInterface
	 **/
	protected $cipher;

	/**
	 * @var integer
	 * The size of the chunked encrypted files
	 **/
	protected $blockSize;

	/**
	 * @var string
	 * The directory where encrypted files are stored
	 **/
	protected $tempDirectory;

	public function __construct(CipherInterface $cipher, $tempDirectory, $chunkFileSize = 1) {
		if($cipher == null || (! $cipher instanceof CipherInterface)) {
			throw new \Exception('The cipher is not an instance of \ODR\AdminBundle\Component\Crypto\CipherInterface');
		}
		$this->cipher 			= $cipher;
		if(! is_dir($tempDirectory)) {
			throw new \Exception('The tempDirectory '.$tempDirectory.' doesn t exist');
		}
		$this->tempDirectory 	= $tempDirectory;
		$this->blockSize 		= ($chunkFileSize * (1024 * 1024));
	}

	public function encryptFile($absolutePath, $key) {
		$folder = $this->tempFolderFromFile($absolutePath);
		$folderToEncrypt = $this->tempDirectory.'/'.$folder;
		try {
			mkdir($folderToEncrypt);
		}
		catch(\Exception $e) {
			throw new \Exception('Impossible to create '.$folderToEncrypt);
		}
		$handle 		= fopen($absolutePath, "rb");
		$chunkFileId 	= 0;
		while(!feof($handle)) {
			$chunk 			= fread($handle, $this->blockSize);
			$handleEncFile 	= fopen($folderToEncrypt.'/'.'enc.'.$chunkFileId, "wb");
			fwrite($handleEncFile, $this->encrypt($chunk, $key));
			fclose($handleEncFile);
			$chunkFileId++;
		}
		fclose($handle);
		try {
			unlink($absolutePath);
		}
		catch(\Exception $e) {
			throw new \Exception('Impossible to delete file '.$absolutePath);
		}
		return true;
	}

	public function decryptFile($absolutePath, $key, $deleteEncFiles = false) {
		$folderWithEncFiles = $this->tempDirectory.'/'.$this->tempFolderFromFile($absolutePath);
		if(! is_dir($folderWithEncFiles)) {
			throw new \Exception('Folder with enc files doesn t exist '.$folderWithEncFiles);
		}
		if(file_exists($absolutePath)) {
			throw new \Exception('File already exists '.$absolutePath);
		}
		$handle 		= fopen($absolutePath, "wb");
		$chunkFileId 	= 0;
		while(file_exists($folderWithEncFiles.'/'.'enc.'.$chunkFileId)) {
			if(! file_exists($folderWithEncFiles.'/'.'enc.'.$chunkFileId)) {
				throw new \Exception('Encrypted file doesn t exist '.$folderWithEncFiles.'/'.'enc.'.$chunkFileId);
			}
			$encryptedData = file_get_contents($folderWithEncFiles.'/'.'enc.'.$chunkFileId);
			fwrite($handle, $this->decrypt($encryptedData, $key));
			$chunkFileId++;
		}
		fclose($handle);
		if($deleteEncFiles) {
			$this->rrmdir($folderWithEncFiles);
		}
		return true;
	}

	public function encrypt($data, $key) {
		return $this->cipher->encrypt($data, $key);
	}

	public function decrypt($data, $key) {
		return $this->cipher->decrypt($data, $key);
	}

	private function tempFolderFromFile($file) {
		return pathinfo($file, PATHINFO_FILENAME);
	}

	private function rrmdir($dir) {
		$files = array_diff(scandir($dir), array('.','..'));
		foreach ($files as $file) {
			(is_dir("$dir/$file")) ? $this->rrmdir("$dir/$file") : unlink("$dir/$file");
		}
		return rmdir($dir);
	}
}
