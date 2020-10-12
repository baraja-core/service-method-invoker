PHP method safe invoke
======================

![Integrity check](https://github.com/baraja-core/service-method-invoker/workflows/Integrity%20check/badge.svg)

Imagine you have instance of your custom service and you want invoke some action method with sets of parameters.

This package is simply way how to invoke all your methods.

📦 Installation & Basic Usage
-----------------------------

This package can be installed using [Package Manager](https://github.com/baraja-core/package-manager) which is also part of the Baraja [Sandbox](https://github.com/baraja-core/sandbox). If you are not using it, you will have to install the package manually using this guide.

*No package configuration is required. Simply create an instance and the class is ready to use immediately.*

To manually install the package call Composer and execute the following command:

```shell
$ composer require baraja-core/service-method-invoker
```

🗺️ Simple example
-----------------

Think of a simple service as an API endpoint with a public method for hydrating your data:

```php
$invoker = new \Baraja\ServiceMethodInvoker;
$apiEndpoint = new \Baraja\MyApiEndpoint;

$data = $invoker->invoke($apiEndpoint, 'actionDetail', ['id' => 42]);

var_dump($data); // return "My id is: 42"
```

And your endpoint can be:

```php
class MyApiEndpoint
{
    public function actionDetail(int $id): string
    {
        return 'My id is: ' . $id;
    }
}
```

📄 License
-----------

`baraja-core/service-method-invoker` is licensed under the MIT license. See the [LICENSE](https://github.com/baraja-core/service-method-invoker/blob/master/LICENSE) file for more details.
