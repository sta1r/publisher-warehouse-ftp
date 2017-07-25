<?php

Class PublisherTransport {

	private $ftp_server = '';
	private $ftp_user_name = '';
	private $ftp_user_pass = '';

	private $ftp_connection;
	private $local_file_path;
	private $local_paths = [];
	private $local_files = [];
	private $orders = [];

	/**
	 * initialiseFtp()
	 * @param string $dir
	 * @return $ftp_connection (FTP stream)
	*/
	public function initialiseFtp($dir) {

		// set up basic connection
		$ftp_connection = ftp_connect($this->ftp_server) or die("Could not connect to $ftp_server");

		// login with username and password
		$logged_in = ftp_login($ftp_connection, $this->ftp_user_name, $this->ftp_user_pass);

		try {
			ftp_chdir($ftp_connection, $dir);
		} catch(Exception $e) {
			echo "ERROR!\n";
			var_dump($e);
		}

		return $ftp_connection;
	}

	/**
	 * sendPacket()
	 * @param string $filename
	*/
	public function sendPacket($filename) {

		$ftp_connection = $this->initialiseFtp("orders-out");

		// turn passive mode on
		ftp_pasv($ftp_connection, true);

		$local_file = $this->getLocalFilePath().$filename;
		$remote_file = $filename;

		// upload a file
		if (ftp_put($ftp_connection, $remote_file, $local_file, FTP_ASCII)) {
			echo "Successfully uploaded $filename\n";
		} else {
			$msg = "There was a problem while uploading $filename in ".__METHOD__.".";
			echo $msg;
			throw new PublisherException($msg);
		}

		ftp_close($ftp_connection);
	}

	/**
	 * checkRemoteFiles()
	*/
	public function checkRemoteFiles() {

		$ftp_connection = $this->initialiseFtp("orders-out");
		$files_to_check = $this->local_files;

		// get file list of current directory
		$remote_file_list = ftp_nlist($ftp_connection, ".");
		ftp_close($ftp_connection);

		// strip out any files already uploaded to remote
		$this->local_files = array_diff($files_to_check, $remote_file_list);

		// cleanUp all dupe files to sent
		$files_to_remove = array_intersect($files_to_check, $remote_file_list);

		$this->moveToFolder($this->getLocalFilePath(), $files_to_remove, 'sent/');
	}

	/**
	 * moveToFolder()
	 * @param string $path
	 * @param array $files
	 * @param string $folder
	*/
	public function moveToFolder($path, $files, $folder) {

		foreach ($files as $file) {
			rename($path.$file, $path.$folder.$file);
			echo "Moved {$file} to $folder folder\n";
		}
	}

	/**
	 * transportXML()
	*/
	public function transportXML() {

		$this->local_paths = $this->getLocalPaths($this->getLocalFilePath(), 'xml');
		$this->local_files = $this->getFileNames($this->local_paths);
		$this->checkRemoteFiles(); // moves dupes to sent and updates list of local_files

		if (!empty($this->local_files)) {
			foreach ($this->local_files as $file) {
				$this->sendPacket($file);
			}
		} else {
			echo "No local files found\n";
		}
	}

	/**
	 * printXML() - for testing
	*/
	public function printXML() {

		$files = glob(DOCROOT.'/ftp/warehouse/orders/*xml');

		if (is_array($files)) {

			header('Content-Type: application/xml');
			$doc = new DOMDocument('1.0');

			foreach ($files as $file) {
				$doc->load($path_to_local_file);
				echo $doc->saveXML(); exit; // can only print one XML doc to screen at once
			}

		} else {
			echo 'No files found';
		}
	}

	/**
	 * getLocalPaths()
	 * Find files of a specified format, and return their full file paths in an array
	 * @param string $path
	 * @param string $format
	 * @return array $local_paths
	*/
	public function getLocalPaths($path, $format) {

		$local_paths = glob($path.'*'.$format);
		if (is_array($local_paths) && count($local_paths) > 0) {
			return $local_paths;
		} else {
			echo "No new orders found\n";
		}
	}

	/**
	 * getFileNames()
	 * Return extracted filenames from an array of paths
	 * @param array $paths
	 * @return array $local_files
	*/
	public function getFileNames($paths) {

		$local_files = [];

		if (is_array($paths) && count($paths) > 0) {
			foreach ($paths as $path) {
				array_push($local_files, $this->extractFileName($path));
			}
			return $local_files;
		} else {
			echo "Local paths array not populated\n";
		}
	}

	/**
	 * extractFileName()
	 * @param string $file
	 * @return string $f
	*/
	public function extractFileName($file) {
		$f = explode('orders/', $file);
		return $f[1];
	}

	/**
	 * setLocalFilePath()
	 * @param string $path
	*/
	public function setLocalFilePath($path) {
		$this->local_file_path = $path;
	}

	/**
	 * getLocalFilePath()
	 * @return string $this->local_file_path
	*/
	public function getLocalFilePath() {
		return $this->local_file_path;
	}
}