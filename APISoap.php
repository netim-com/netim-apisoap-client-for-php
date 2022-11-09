<?php

/** 
 * @created 03/10/17
 * @lastUpdated 31/08/21
 * @version 2.0.0
 *  
 * Generic class for a client API. Handle the SOAP connection to NETIM's API, and many operation described here: http://support.netim.com/en/wiki/Category:Functions
 * 
 * How to use the class?
 * =====================
 * 
 * Beforehand you need to include this script into your php script:
 * ```php
 * 		include_once('$PATH/APISoap.php');
 * 		//(replace $PATH by the path of the file)
 * ```
 * 
 * Then you can instantiate a APISoap object:
 * ```php
 * 		$username = 'yourUsername';
 * 		$secret = 'yourSecret';
 * 		$client = new APISoap($username, $secret);
 * ```
 * 
 * You can also create a conf.xml file next to the APISoap.php class with the login credentials to connect to the API with no parameters
 * 	
 * Now that you have your object, you can issue commands to the API.
 * 
 * Say you want to see the information you gave when creating your contact, and your contact ID is 'GK521'.
 * The code is:
 * ```php
 * 		$result = $client->contactInfo('GK521');
 * ```
 * 
 * (SIDENOTE: you may have noticed that you didn't need to explicitely open nor close a connexion with the API, the client handle it for you.
 * It is good for shortlived scripts. The connection is automatically stopped when the script ends. However if you open multiple connections
 * in a long running script, you should close each connection when you don't need them anymore to avoid having too many connections opened).
 * 
 * To know if there is an error we provide you an exception type NetimAPIException
 * 
 * How to issue many commands more effectively
 * ===========================================
 * 
 * Previously we saw how to issue a simple command. Now we will look into issueing many commands sequentially.
 * 
 * Let's take an example, we want to create 2 contacts, look up info on 2 domains and look up infos on the contacts previously created
 * We could do it simply:
 * ```php
 * 		//creating contacts
 * 		try
 * 		{
 * 			$result1 = $client->contactCreate(...); //skipping needed parameters here for the sake of the example brevity
 * 			$result2 = $client->contactCreate(...);
 * 			
 * 			//asking for domain informations
 * 			$result3 = $client->domainInfo('myDomain.fr');
 * 			$result4 = $client->domainInfo('myDomain.com');
 * 		}
 * 		catch (NetimAPIException $exception)
 * 		{
 * 			//do something about the error
 * 		}
 * 		
 * 		//asking for contact informations
 * 		$result5 = $client->contactInfo($result1));
 * 		$result6 = $client->contactInfo($result2));
 * ```
 * 	
 * The connection is automatically closed when the script ends. However we recommend you to close the connection yourself when you won't use it
 * anymore like so : 
 * ```php
 * 		$client->sessionClose();
 * ```
 * The reason is that PHP calls the destructor only if it's running out of memory or when the script ends. If your script is running in a cron for
 * example, and it instanciates many APISoap objects without closing them, you may reach the limit of sessions you're allowed to open.
 */

namespace Netim {

	use stdClass;

	require_once __DIR__ . '/NetimAPIException.php';
	require_once __DIR__ . '/AbstractAPISoap.php';


	class APISoap extends AbstractAPISoap
	{

		/**
		 * Constructor for class APISoap
		 *
		 * @param string $userID the ID the client uses to connect to his NETIM account
		 * @param string $password the PASSWORD the client uses to connect to his NETIM account
		 *	 
		 * @throws Error if $userID, $password or $apiURL are not string or are empty
		 * 
		 * @link semantic versionning http://semver.org/ by Tom Preston-Werner 
		 */
		public function __construct(string $userID = null, string $password = null)
		{

			$confpath = dirname(__FILE__) . "/conf.xml";
			if (!file_exists($confpath))
				throw new NetimAPIException("Missing conf.xml file.");

			$conf = get_object_vars(simplexml_load_file($confpath));

			if (is_null($userID) && is_null($password)) //No parameters
			{
				if (!array_key_exists('login', $conf) || empty($conf['login']))
					throw new NetimAPIException("Missing or empty <login> in conf file.");

				if (!array_key_exists('password', $conf) || empty($conf['password']))
					throw new NetimAPIException("Missing or empty <password> in conf file.");

				$userID = trim($conf['login']);
				$password = trim($conf['password']);
			} else //With parameters
			{
				if (empty($userID))
					throw new NetimAPIException("Missing \$userID.");

				if (empty($password))
					throw new NetimAPIException("Missing \$password.");
			}

			if (!array_key_exists('url', $conf) || empty($conf['url']))
				throw new NetimAPIException("Missing or empty <url> in conf file.");

			$apiURL = $conf['url'];

			if (in_array($conf['language'], array("EN", "FR")))
				$defaultLanguage = $conf['language'];
			else
				$defaultLanguage = "EN";

			parent::__construct($userID, $password, $apiURL, $defaultLanguage);
		}

		public function sessionOpen($lang = ""): void
		{
			parent::_login();
		}

		# -------------------------------------------------
		# CONTACT
		# -------------------------------------------------	        

		/**
		 * Creates a contact
		 *
		 * Example1: non-owner
		 *	```php
		 *	//we create a contact as a non-owner 
		 *	$id = null;
		 *	try
		 *	{
		 *		$contact = array(
		 *	 		'firstName'=> 'barack',
		 *			'lastName' => 'obama',
		 *			'bodyForm' => 'IND',
		 *			'bodyName' => '',
		 *			'address1' => '1600 Pennsylvania Ave NW',
		 *			'address2' => '',
		 *			'zipCode'  => '20500',
		 *			'area'	   => 'DC',
		 *			'city'	   => 'Washington',
		 *			'country'  => 'US',
		 *			'phone'	   => '2024561111',
		 *			'fax'	   => '',
		 *			'email'    => 'barack.obama@gov.us',
		 *			'language' => 'EN',
		 *			'isOwner'  => 0
		 *		);
		 *		$id = $client->contactCreate($contact);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something about the error
		 *	}
		 *
		 *	//continue processing
		 *	```
		 *
		 * Example2: owner
		 *	```php	
		 *	$id = null;
		 *	try
		 *	{
		 *	 	$contact = array(
		 *	 		'firstName'=> 'bill',
		 *			'lastName' => 'gates',
		 *			'bodyForm' => 'IND',
		 *			'bodyName' => '',
		 *			'address1' => '1 hollywood bvd',
		 *			'address2' => '',
		 *			'zipCode'  => '18022',
		 *			'area'	   => 'LA',
		 *			'city'	   => 'Los Angeles',
		 *			'country'  => 'US',
		 *			'phone'	   => '2024531111',
		 *			'fax'	   => '',
		 *			'email'    => 'bill.gates@microsoft.com',
		 *			'language' => 'EN',
		 *			'isOwner'  => 1
		 *		);
		 *		$id = $client->contactCreate($contact);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something about the error
		 *	}
		 *
		 *	//continue processing
		 *	```
		 * @param StructContact $contact the contact to create
		 *
		 * @throws NetimAPIException
		 *
		 * @return string the ID of the contact
		 *
		 * @see StructContact http://support.netim.com/en/wiki/StructContact
		 */
		public function contactCreate(array $contact): string
		{
			return parent::_contactCreate($contact);
		}

		/**
		 * Returns all informations about a contact object
		 *
		 * Example:
		 *	```php
		 *	$idContact = 'BJ007';
		 *	$res = null;
		 *	try 
		 *	{
		 *		$res = $client->contactInfo($idContact);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something about the error
		 *	}
		 *	$contactInfo = $res;
		 *	//continue processing
		 *	```
		 * @param string $idContact ID of the contact to be queried
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructContactReturn information on the contact
		 *
		 * @see contactInfo API http://support.netim.com/en/wiki/ContactInfo
		 * @see StructContactReturn API http://support.netim.com/en/wiki/StructContactReturn
		 */
		public function contactInfo(string $idContact): stdClass
		{
			return parent::_contactInfo($idContact);
		}

		/**
		 * Edit contact details
		 *
		 * Example: 
		 *	```php
		 *	//we update a contact as a non-owner 
		 *	$res = null;
		 *	try {
		 *	 	$contact = array(
		 *	 		'firstName'=> 'donald',
		 *			'lastName' => 'trump',
		 *			'bodyForm' => 'IND',
		 *			'bodyName' => '',
		 *			'address1' => '1600 Pennsylvania Ave NW',
		 *			'address2' => '',
		 *			'zipCode'  => '20500',
		 *			'area'	   => 'DC',
		 *			'city'	   => 'Washington',
		 *			'country'  => 'US',
		 *			'phone'	   => '2024561111',
		 *			'fax'	   => '',
		 *			'email'    => 'donald.trump@gov.us',
		 *			'language' => 'EN',
		 *			'isOwner'  => 0
		 *		);
		 *		$res = $client->contactUpdate($idContact, $contact);   
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 * ```
		 *
		 * @param string $idContact the ID of the contact to be updated
		 * @param StructContact $contact the contact object containing the new values
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see contactUpdate API http://support.netim.com/en/wiki/ContactUpdate
		 */
		public function contactUpdate(string $idContact, array $datas): stdClass
		{
			return parent::_contactUpdate($idContact, $datas);
		}

		/**
		 * Edit contact details (for owner only) 
		 *
		 * Example
		 *	```php
		 *	//we update a owner contact
		 *	$res = null;
		 *	try
		 *	{
		 *			$contact = array(
		 *	 		'firstName'=> 'elon',
		 *			'lastName' => 'musk',
		 *			'bodyForm' => 'IND',
		 *			'bodyName' => '',
		 *			'address1' => '1 hollywood bvd',
		 *			'address2' => '',
		 *			'zipCode'  => '18022',
		 *			'area'	   => 'LA',
		 *			'city'	   => 'Los Angeles',
		 *			'country'  => 'US',
		 *			'phone'	   => '2024531111',
		 *			'fax'	   => '',
		 *			'email'    => 'elon.musk@tesla.com',
		 *			'language' => 'EN',
		 *			'isOwner'  => 1
		 *		);
		 *		$res = $client->contactOwnerUpdate($idContact, $contact); 
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param string $idContact the ID of the contact to be updated
		 * @param StructOwnerContact $contact the contact object containing the new values
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see contactOwnerUpdate API http://support.netim.com/en/wiki/ContactOwnerUpdate
		 * @see StructOwnerContact http://support.netim.com/en/wiki/StructOwnerContact
		 * 
		 */
		public function contactOwnerUpdate(string $idContact, array $datas): stdClass
		{
			return parent::_contactOwnerUpdate($idContact, $datas);
		}

		/**
		 * Deletes a contact object 
		 *
		 * Example1:
		 *	```php
		 *	$contactID = 'BJ007';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->contactDelete($contactID);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * @param string $idContact ID of the contact to be deleted
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see contactDelete API http://support.netim.com/en/wiki/ContactDelete
		 * @see StructOperationResponse API http://support.netim.com/en/wiki/StructOperationResponse
		 */
		public function contactDelete(string $idContact): stdClass
		{
			return parent::_contactDelete($idContact);
		}
	}
}
