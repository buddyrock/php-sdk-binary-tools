<?php

namespace SDK\Build\PGO\Server;

use SDK\Build\PGO\Interfaces\Server\DB;
use SDK\Build\PGO\Abstracts\Server;
use SDK\Build\PGO\Config as PGOConfig;
use SDK\{Config as SDKConfig, Exception, FileOps};
use SDK\Build\PGO\Tool\PackageWorkman;

class MariaDB extends Server implements DB
{
	use FileOps;

	protected $conf;
	protected $base;
	protected $name = "MariaDB";

	public function __construct(PGOConfig $conf)
	{
		$this->conf = $conf;
		$this->base = $conf->getSrvDir(strtolower($this->name));
	}

	protected function setupDist()
	{
		/* pass */
	}

	public function prepareInit(PackageWorkman $pw, bool $force = false) : void
	{
		$url = $this->conf->getSectionItem($this->name, "pkg_url");
		$pw->fetchAndUnzip($url, "mariadb.zip", $this->conf->getSrvDir(), "mariadb", $force);
	}

	public function init() : void
	{
		echo "Initializing " . $this->name . ".\n";

		$this->setupDist();

		$this->up();
		$this->down(true);

		echo $this->name . " initialization done.\n";
	}

	public function up() : void
	{
		echo "Starting " . $this->name . ".\n";

		$cwd = getcwd();

		chdir($this->base);

		$port = $this->conf->getSectionItem($this->name, "port");

		$h = popen("start /b .\\bin\\mysqld.exe --port=$port >nul 2>&1", "r");
		/* XXX error check*/
		pclose($h);

		chdir($cwd);

		echo $this->name . " started.\n";
	}

	public function down(bool $force = false) : void
	{
		echo "Stopping " . $this->name . ".\n";

		$cwd = getcwd();

		chdir($this->base);

		$user = $this->conf->getSectionItem($this->name, "user");
		$pass = $this->conf->getSectionItem($this->name, "pass");
		$host = $this->conf->getSectionItem($this->name, "host");
		$port = $this->conf->getSectionItem($this->name, "port");

		$cmd = sprintf(".\\bin\\mysqladmin.exe --host=$host --port=$port -u $user %s--shutdown_timeout=0 shutdown", ($pass ? "-p$pass " : ""));
		exec($cmd);

		if ($force) {
			sleep(1);
			exec("taskkill /f /im nginx.exe >nul 2>&1");
		}

		chdir($cwd);

		echo $this->name . " stopped.\n";
	}

	public function query(string $s, string $db = NULL) : void
	{
		$ret = NULL;

		$cwd = getcwd();

		chdir($this->base);

		$user = $this->conf->getSectionItem($this->name, "user");
		$pass = $this->conf->getSectionItem($this->name, "pass");
		$host = $this->conf->getSectionItem($this->name, "host");
		$port = $this->conf->getSectionItem($this->name, "port");

		$pass_arg = $pass ? "-p$pass " : "";
		$ret = shell_exec(".\bin\mysql.exe -u $user $pass_arg -h $host -P $port -e \"$s\"");

		chdir($cwd);
	}
}
