## Description
This client is designed to communicate with the netim SOAP API.
It can be integrated into all your PHP projects.

## Configuration
The configuration is done via the conf.xml file where you can specify the API URL, your login, the secret and the language of your choice.
For the login and the secret you can also override them at the instantiation of the object by giving the login and the secret to the constructor

```xml
<?xml version="1.0" encoding="utf-8"?>
<configuration>
    <url>http://oteapi.netim.com/2.0/api.wsdl</url>
    <login>login</login>
    <password>password</password>
    <language>EN</language>
</configuration>
```

## Utilisation
To communicate with the API, instantiate an APIRest object and use its methods to communicate.

```php
include 'APISoap.php';

$api = new APISoap();

//Exemple
$api->hello();

```
By default, the session is closed when the object is destroyed. It is advisable to manually close the session when you no longer need it if you instantiate the object several times, so as not to exceed your session quota.

```php
$api = new APISoap();

$api->hello();

$api->sessionClose();

// From here the session is closed. If you want to reuse the session later you will have to do api.sessionOpen();
```