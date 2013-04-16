<?php
// Copyright (c) 2013 Datenstrom, http://www.datenstrom.se
// This file may be used and distributed under the terms of the public license.

// Web interface core plugin
class Yellow_Webinterface
{
	const Version = "0.0.0";	//Hello web interface!
	var $yellow;			//access to API
	var $users;				//web interface users
	var $activeLocation;	//web interface location? (boolean)
	var $activeUserFail;	//web interface login failed (boolean)
	var $activeUserEmail;	//web interface user currently logged in

	// Initialise plugin
	function initPlugin($yellow)
	{
		$this->yellow = $yellow;
		$this->yellow->config->setDefault("webinterfaceLocation", "/wiki/");
		$this->yellow->config->setDefault("webinterfaceUserFile", "user.ini");
		$this->users = new Yellow_WebinterfaceUsers();
		$this->users->load($this->yellow->config->get("configDir").$this->yellow->config->get("webinterfaceUserFile"));
	}

	// Handle web interface location
	function onRequest($baseLocation, $location, $fileName)
	{
		$statusCode = 0;
		if($this->checkWebinterfaceLocation($location))
		{
			$baseLocation .= rtrim($this->yellow->config->get("webinterfaceLocation"), '/');
			$location = $this->yellow->getRelativeLocation($baseLocation);
			$fileName = $this->yellow->getContentFileName($location);
			if($this->checkUser()) $statusCode = $this->processRequestAction($baseLocation, $location, $fileName);
			if($statusCode == 0) $statusCode = $this->yellow->processRequestFile($baseLocation, $location, $fileName,
													$this->activeUserFail ? 401 : 0, false);
		} else {
			if($this->yellow->config->get("webinterfaceLocation") == "$location/")
			{
				$statusCode = 301;
				$this->yellow->sendStatus($statusCode, "Location: http://$_SERVER[SERVER_NAME]$baseLocation$location/");
			}
		}
		return $statusCode;
	}
	
	// Handle extra HTML header lines
	function onHeaderExtra()
	{
		$header = "";
		if($this->isWebinterfaceLocation())
		{
			$location = $this->yellow->config->getHtml("baseLocation").$this->yellow->config->getHtml("pluginsLocation");
			$language = $this->isUser() ? $this->users->getLanguage($this->activeUserEmail) : $this->yellow->page->get("language");
			$header .= "<link href=\"{$location}core_webinterface.css\" rel=\"styleSheet\" media=\"all\" type=\"text/css\" />\n";
			$header .= "<script type=\"text/javascript\" src=\"{$location}core_webinterface.js\"></script>\n";
			$header .= "<script type=\"text/javascript\">\n";
			$header .= "// <![CDATA[\n";
			if($this->isUser())
			{
				$header .= "yellow.page.rawData = ".json_encode($this->yellow->page->rawData).";\n";
				$header .= "yellow.pages = ".json_encode($this->getPagesData()).";\n";
				$header .= "yellow.config = ".json_encode($this->getConfigData($this->activeUserEmail)).";\n";
			}
			$header .= "yellow.text = ".json_encode($this->yellow->text->getData($language, "webinterface")).";\n";
			if(defined("DEBUG")) $header .= "yellow.debug = ".json_encode(DEBUG).";\n";
			$header .= "// ]]>\n";
			$header .= "</script>\n";
		}
		return $header;
	}
	
	// Handle page before parser
	function onParseBefore($text, $statusCode)
	{
		if($this->isWebinterfaceLocation() && $this->isUser())
		{
			if($statusCode == 424)
			{
				$this->yellow->page->rawData = "---\r\n";
				$this->yellow->page->rawData .= "Title: ".$this->yellow->text->get("webinterface424Title")."\r\n";
				$this->yellow->page->rawData .= "Author: ".$this->users->getName($this->activeUserEmail)."\r\n";
				$this->yellow->page->rawData .= "---\r\n";
				$this->yellow->page->rawData .= $this->yellow->text->get("webinterface424Text");
			}
		}
		return $text;
	}
	
	// Handle page after parser
	function onParseAfter($text, $statusCode)
	{
		if($this->isWebinterfaceLocation() && $this->isUser())
		{
			$this->yellow->toolbox->timerStart($time);
			$baseLocation = $this->yellow->config->get("baseLocation");
			$webinterfaceLocation = rtrim($this->yellow->config->get("webinterfaceLocation"), '/');
			$text = preg_replace("#<a(.*?)href=\"$baseLocation(?!$webinterfaceLocation)(.*?)\"(.*?)>#",
								 "<a$1href=\"$baseLocation$webinterfaceLocation$2\"$3>", $text);
		}
		return $text;
	}
	
	// Process request for an action
	function processRequestAction($baseLocation, $location, $fileName)
	{
		$statusCode = 0;
		if($_POST["action"] == "edit")
		{
			if(strlen($_POST["rawdata"]))
			{
				$fileHandle = @fopen($fileName, "w");
				if($fileHandle)
				{
					fwrite($fileHandle, $_POST["rawdata"]);
					fclose($fileHandle);
				} else {
					die("Configuration problem: Can't write page '$fileName'!");
				}
			}
		} else if($_POST["action"]== "logout") {
			$this->users->destroyCookie("login");
			$this->activeUserEmail = "";
			$statusCode = 302;
			$newLocation = $this->yellow->config->getHtml("baseLocation").$location;
			$this->yellow->sendStatus($statusCode, "Location: http://$_SERVER[SERVER_NAME]$newLocation");
		} else {
			if(!is_readable($fileName))
			{
				if($this->yellow->toolbox->isFileLocation($location) && is_dir($this->yellow->getContentDirectory("$location/")))
				{
					$statusCode = 301;
					$this->yellow->sendStatus($statusCode, "Location: http://$_SERVER[SERVER_NAME]$baseLocation$location/");
				} else {
					$statusCode = $this->checkUserPermissions($location, $fileName) ? 424 : 404;
					$this->yellow->processRequestFile($baseLocation, $location, $fileName, $statusCode, false);
				}
			}
		}
		return $statusCode;
	}
	
	// Check web interface location
	function checkWebinterfaceLocation($location)
	{
		$locationLength = strlen($this->yellow->config->get("webinterfaceLocation"));
		$this->activeLocation = substr($location, 0, $locationLength) == $this->yellow->config->get("webinterfaceLocation");
		return $this->isWebinterfaceLocation();
	}
	
	// Check user login
	function checkUser()
	{
		if($_POST["action"] == "login")
		{
			$email = $_POST["email"];
			$password = $_POST["password"];
			if($this->users->checkUser($email, $password))
			{
				$this->users->createCookie("login", $email);
				$this->activeUserEmail = $email;
			} else {
				$this->activeUserFail = true;
			}
		} else if(isset($_COOKIE["login"])) {
			$cookie = $_COOKIE["login"];
			if($this->users->checkCookie($cookie))
			{
				$this->activeUserEmail = $this->users->getCookieEmail($cookie);
			} else {
				$this->activeUserFail = true;
			}
		}
		return $this->isUser();
	}
	
	// Check users permissions for creating new page
	function checkUserPermissions($location, $fileName)
	{
		$path = dirname($fileName);
		return is_dir($path);
	}
	
	// Check if web interface location
	function isWebinterfaceLocation()
	{
		return $this->activeLocation;
	}
	
	// Check if user is logged in
	function isUser()
	{
		return !empty($this->activeUserEmail);
	}

	// Return page tree with content/media information
	function getPagesData()
	{
		$data = array();
		foreach($this->yellow->pages->root(true) as $page)
		{
			$data[$page->fileName] = array();
			$data[$page->fileName]["location"] = $page->getLocation();
			$data[$page->fileName]["title"] = $page->getTitle();
		}
		return $data;
	}
	
	// Return configuration data including user information
	function getConfigData($email)
	{
		$data = array("userEmail" => $email,
					  "userName" => $this->users->getName($email),
					  "userLanguage" => $this->users->getLanguage($email),
					  "baseLocation" => $this->yellow->config->get("baseLocation"));
		return array_merge($data, $this->yellow->config->getData("Location"));
	}
}

// Yellow web interface users
class Yellow_WebinterfaceUsers
{
	var $users;		//registered users
	
	function __construct()
	{
		$this->users = array();
	}

	// Load users from file
	function load($fileName) 
	{
		$fileData = @file($fileName);
		if($fileData)
		{
			foreach($fileData as $line)
			{
				if(preg_match("/^\//", $line)) continue;
				preg_match("/^(.*?)\s*,(.*?),\s*(.*?),\s*(.*?)\s*$/", $line, $matches);
				if($matches[1]!="" && $matches[2]!="" && $matches[3]!="" && $matches[4]!="")
				{
					$this->setUser($matches[1], $matches[2], $matches[3], $matches[4]);
					if(defined("DEBUG") && DEBUG>=3) echo "Yellow_WebinterfaceUsers::load email:$matches[1] $matches[3]<br/>\n";
				}
			}
		}
	}

	// Set user data
	function setUser($email, $password, $name, $language)
	{
		$this->users[$email] = array();
		$this->users[$email]["email"] = $email;
		$this->users[$email]["password"] = $password;
		$this->users[$email]["name"] = $name;
		$this->users[$email]["language"] = $language;
		$this->users[$email]["session"] = hash("sha256", $email.$password.strrev($email.$password));
	}
	
	// Check user login
	function checkUser($email, $password)
	{
		return $this->isExisting($email) && hash("sha256", $email.$password)==$this->users[$email]["password"];
	}

	// Create browser cookie
	function createCookie($cookieName, $email)
	{
		if($this->isExisting($email))
		{
			$salt = hash("sha256", uniqid(mt_rand(), true));
			$text = $email.";".$salt.";".hash("sha256", $salt.$this->users[$email]["session"]);
			setcookie($cookieName, $text, time()+60*60*24*30*365*10, "/") || die("Server problem: Can't create '$cookieName' cookie!");
		}
	}
	
	// Destroy browser cookie
	function destroyCookie($cookieName)
	{
		setcookie($cookieName, "", time()-3600, "/");
	}
	
	// Check user login from browser cookie
	function checkCookie($cookie)
	{
		list($email, $salt, $session) = explode(";", $cookie);
		return $this->isExisting($email) && hash("sha256", $salt.$this->users[$email]["session"])==$session;
	}
	
	// Return user email from browser cookie
	function getCookieEmail($cookie)
	{
		list($email, $salt, $session) = explode(";", $cookie);
		return $email;
	}
	
	// Return user name
	function getName($email)
	{
		return $this->isExisting($email) ? $this->users[$email]["name"] : "";
	}

	// Return user language
	function getLanguage($email)
	{
		return $this->isExisting($email) ? $this->users[$email]["language"] : "";
	}	
	
	// Check if user exists
	function isExisting($email)
	{
		return !is_null($this->users[$email]);
	}
}

$yellow->registerPlugin("webinterface", "Yellow_Webinterface", Yellow_Webinterface::Version);
?>