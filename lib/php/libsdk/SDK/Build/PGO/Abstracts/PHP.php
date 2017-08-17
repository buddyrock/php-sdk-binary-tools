<?php

namespace SDK\Build\PGO\Abstracts;

use SDK\Build\PGO\Interfaces\Server;
use SDK\Build\PGO\PHP\CLI;
use SDK\Build\PGO\Config as PGOConfig;
use SDK\{Config as SDKConfig, Exception, FileOps};

abstract class PHP
{
	protected $php_root;
	protected $php_ext_root;

	protected function setupPaths()
	{
		$this->php_root = $this->getRootDir();
		if ($this->isDist()) {
			$this->php_ext_root = $this->php_root . DIRECTORY_SEPARATOR . "ext";
			if (!file_exists($this->php_ext_root)) {
				throw new Exception("Extension dir '{$this->php_ext_root}' doesn't exist.");
			}
		} else {
			$this->php_ext_root = $this->php_root;
		}
	}

	/* TODO Might be improved. */
	public function isDist() : bool
	{
		return !file_exists("Makefile") && file_exists("php.exe");
	}

	protected function createEnv() : array
	{
		$env = getenv();

		if (!$this->isDist()) {
			$deps_root = SDKConfig::getDepsLocalPath();
			foreach ($env as $k => $v) {
				if (strtoupper($k) == "PATH") {
					$env[$k] = "$deps_root" . DIRECTORY_SEPARATOR . "bin;" . $env[$k];
					break;
				}
			}
		}

		return $env;
	}

	public function getExtRootDir() : string
	{
		return $this->php_ext_root;
	}

	public function getRootDir() : string
	{
		if ($this->php_root) {
			return $this->php_root;
		}

		/* XXX adapt for any possible PHP variants. */
		$root = getenv("PHP_SDK_PGO_TEST_PHP_ROOT");
		if (!$root) {
			if (!$this->isDist()) {
				$s = file_get_contents("Makefile");
				if (preg_match(",BUILD_DIR=(.+),", $s, $m) > 0) {
					$root = trim($m[1]);
				}
			}
		}

		if (!file_exists($root)) {
			throw new Exception("'$root' doesn't exist.");
		}

		return $root;
	}


	public function getVersion(bool $short = false) : string
	{
		$ret = NULL;
		$cli = new CLI($this->conf, $this->scenario);

		$out = shell_exec($cli->getExeFilename() . " -n -v");

		if ($short) {
			if (preg_match(",PHP (\d+\.\d+),", $out, $m)) {
				$ret = $m[1];
			}
		} else {
			if (preg_match(",PHP ([^ ]+),", $out, $m)) {
				$ret = $m[1];
			}
		}

		if (is_null($ret)) {
			throw new Exception("Failed to determine the test PHP version.");
		}

		return $ret;
	}

	public function isThreadSafe() : bool
	{
		$cli = new CLI($this->conf, $this->scenario);

		$out = shell_exec($cli->getExeFilename() . " -n -v");

		if (preg_match(",NTS,", $out, $m) > 0) {
			return false;
		}

		return true;
	}

	/* Need to cleanup it somewhere. */
	public function getIniFilename()
	{
		$ret = tempnam(sys_get_temp_dir(), "ini");

		$this->conf->processTplFile(
			$this->getIniTplFilename(),
			$ret,
			array(
				$this->conf->buildTplVarName("php", "extension_dir") => $this->php_ext_root,
			)
		);

		return $ret;
	}

	protected function getIniTplFilename()
	{
		$tpl_path = $this->conf->getTplDir("php");
		$version = $this->getVersion(true);
		$ts = $this->isThreadSafe() ? "ts" : "nts";

		$construct = $tpl_path . DIRECTORY_SEPARATOR . "php-$version-pgo-$ts" . ("default" == $this->scenario ? "" : "-{$this->scenario}") . ".ini"; 

		if (!file_exists($construct)) {
			throw new Exception("Couldn't locate PHP config under '$construct'.");
		}

		return $construct;
	}

	public function exec(string $php_cmd, string $args = NULL, array $extra_env = array()) : int
	{
		$env = $this->createEnv();
		$exe  = $this->getExeFilename();
		$ini  = $this->getIniFilename();

		$cert_path = getenv("PHP_SDK_ROOT_PATH") . "\\msys2\\usr\\ssl\\cert.pem";
		$ini .= " -d curl.cainfo=$cert_path";

		foreach ($env as $k0 => &$v0) {
			foreach ($extra_env as $k1 => $v1) {
				if (strtoupper($k0) == strtoupper($k1)) {
					/* XXX some more things could require extra handling. */
					if (strtoupper($k0) == "PATH") {
						$v0 = "$v1;$v0";
					} else {
						$v0 = $v1;
					}
					break;
				}
			}
		}

		$cmd = "$exe -n -c $ini " . ($args ? "$args " : "") . "$php_cmd";

		$desc = array(
			0 => array("file", "php://stdin", "r"),
			1 => array("file", "php://stdout", "w"),
			2 => array("file", "php://stderr", "w")
		);
		$p = proc_open($cmd, $desc, $pipes, $this->getRootDir(), $env);

		return proc_close($p);
	}
}

