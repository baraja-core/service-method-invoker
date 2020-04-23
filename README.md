PHP method safe invoke
======================

Imagine you have instance of your custom service and you want invoke some action method with sets of parameters.

This package is simply way how to invoke all your methods.

Simple example:

```php
$invoker = new \Baraja\ServiceMethodInvoker;

$apiEndpoint = new \Baraja\MyApiEndpoint;

$data = $invoker->invoke($apiEndpoint, 'actionDetail', ['id' => 42]);

var_dump($data); // return "My id is: 42"
```

And your endpoint can be:

```php
class ServiceMethodInvoker
{
    public function actionDetail(int $id): string
    {
        return 'My id is: ' . $id;
    }
}
```
