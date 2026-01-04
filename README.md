# Service Method Invoker

![Integrity check](https://github.com/baraja-core/service-method-invoker/workflows/Integrity%20check/badge.svg)

A robust PHP library for safely invoking methods on services with automatic parameter validation, type coercion, and entity hydration. Perfect for API endpoints, command handlers, and any scenario where you need to dynamically call methods with user-provided data.

## 🎯 Key Features

- **Safe method invocation** with automatic parameter validation and type checking
- **Automatic type coercion** for scalar types (bool, int, float, string, array)
- **Entity hydration** - automatically converts array data to typed objects/DTOs
- **PHP 8.1+ enum support** with case-insensitive matching
- **Nullable parameter handling** with proper default value support
- **Tracy Debugger integration** with custom BlueScreen panels for debugging
- **Circular reference detection** in nested entity hydration
- **Custom entity repository support** for resolving entities by ID
- **Detailed exception messages** with context about the failing service and method

## 🏗️ Architecture

The package consists of several components working together to provide safe method invocation:

```
┌─────────────────────────────────────────────────────────────────┐
│                    ServiceMethodInvoker                         │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  invoke(service, method, params)                        │   │
│  │  getInvokeArgs(service, method, params)                 │   │
│  └─────────────────────────────────────────────────────────┘   │
│           │                    │                    │           │
│           ▼                    ▼                    ▼           │
│  ┌─────────────┐    ┌─────────────────┐    ┌──────────────┐    │
│  │Type Coercion│    │Entity Hydration │    │Enum Handling │    │
│  │ fixType()   │    │hydrateDataTo..()│    │processEnum..()│   │
│  └─────────────┘    └─────────────────┘    └──────────────┘    │
└─────────────────────────────────────────────────────────────────┘
         │                       │
         ▼                       ▼
┌─────────────────┐    ┌─────────────────────────┐
│     Helpers     │    │ ProjectEntityRepository │
│ - Type parsing  │    │ - Find entities by ID   │
│ - Annotations   │    │ (optional interface)    │
│ - Use statements│    └─────────────────────────┘
└─────────────────┘
         │
         ▼
┌─────────────────┐    ┌─────────────────────────┐
│   BlueScreen    │    │  RuntimeInvokeException │
│ Tracy panel     │    │  Contextual exceptions  │
└─────────────────┘    └─────────────────────────┘
```

### 🔧 Components

| Component | Description |
|-----------|-------------|
| `ServiceMethodInvoker` | Main class responsible for invoking methods with validated and hydrated parameters |
| `Service` | Interface for services that extends `Stringable` for user-friendly error messages |
| `RuntimeInvokeException` | Exception class that carries service context, method name, and parameters for debugging |
| `BlueScreen` | Tracy Debugger integration that renders detailed error panels with source code |
| `Helpers` | Utility class for type resolution, annotation parsing, and use statement extraction |
| `ProjectEntityRepository` | Interface for custom entity resolution by ID (e.g., Doctrine integration) |

## 📦 Installation

It's best to use [Composer](https://getcomposer.org) for installation, and you can also find the package on
[Packagist](https://packagist.org/packages/baraja-core/service-method-invoker) and
[GitHub](https://github.com/baraja-core/service-method-invoker).

To install, simply use the command:

```shell
$ composer require baraja-core/service-method-invoker
```

You can use the package manually by creating an instance of the internal classes, or register a DIC extension to link the services directly to the Nette Framework.

**Requirements:** PHP 8.1 or higher

## 🚀 Basic Usage

### Simple Method Invocation

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

### Getting Invoke Arguments Without Execution

If you need to prepare the arguments without actually invoking the method:

```php
$invoker = new \Baraja\ServiceMethodInvoker;
$args = $invoker->getInvokeArgs($service, 'methodName', $params);

// $args is now a validated and hydrated array ready for method invocation
```

## 🎭 Type Coercion

The invoker automatically handles type conversion based on method parameter types:

### Scalar Types

```php
class MyService
{
    public function process(
        int $count,      // "42" -> 42, "null" -> null (if nullable)
        float $price,    // "19.99" -> 19.99
        bool $active,    // "true", "1", "yes" -> true; others -> false
        string $name,    // kept as-is
        array $items,    // kept as-is
    ): void {
        // ...
    }
}

$invoker->invoke($service, 'process', [
    'count' => '42',
    'price' => '19.99',
    'active' => 'yes',
    'name' => 'Test',
    'items' => ['a', 'b'],
]);
```

### Nullable Parameters

When a parameter is nullable and receives an empty or falsy value, it's converted to `null`:

```php
public function find(?int $id): void
{
    // '' or '0' with nullable type -> null
    // '0' with non-nullable int -> 0
}
```

### Boolean Conversion

The following string values are treated as `true`: `"1"`, `"true"`, `"yes"` (case-insensitive).
All other values are converted to `false`.

## 📊 Entity Hydration

One of the most powerful features is automatic entity/DTO hydration from array data:

### Simple Entity

```php
class CreateUserRequest
{
    public function __construct(
        public string $email,
        public string $name,
        public ?int $age = null,
    ) {}
}

class UserController
{
    public function create(CreateUserRequest $request): User
    {
        // $request is automatically hydrated from the params array
        return new User($request->email, $request->name);
    }
}

$invoker->invoke($controller, 'create', [
    'email' => 'john@example.com',
    'name' => 'John Doe',
    'age' => 25,
]);
```

### Nested Entities

The invoker supports nested entity hydration:

```php
class Address
{
    public function __construct(
        public string $street,
        public string $city,
    ) {}
}

class Order
{
    public function __construct(
        public string $product,
        public Address $shippingAddress,
    ) {}
}

$invoker->invoke($service, 'createOrder', [
    'product' => 'Widget',
    'shippingAddress' => [
        'street' => '123 Main St',
        'city' => 'Prague',
    ],
]);
```

### Property Hydration via Setters

If an entity has setter methods, they will be used for hydration:

```php
class UserEntity
{
    private string $email;

    public function setEmail(string $email): void
    {
        $this->email = strtolower($email);
    }
}
```

### Circular Reference Detection

The invoker detects and prevents circular references in entity hydration:

```php
class NodeA
{
    public NodeB $child;
}

class NodeB
{
    public NodeA $parent; // This would cause a circular reference
}

// RuntimeInvokeException: Circular reference detected...
```

## 🔢 PHP 8.1+ Enum Support

The invoker fully supports PHP enums with case-insensitive matching:

```php
enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}

class OrderService
{
    public function updateStatus(int $orderId, Status $status): void
    {
        // ...
    }
}

// All of these work:
$invoker->invoke($service, 'updateStatus', ['orderId' => 1, 'status' => 'active']);
$invoker->invoke($service, 'updateStatus', ['orderId' => 1, 'status' => 'ACTIVE']);
$invoker->invoke($service, 'updateStatus', ['orderId' => 1, 'status' => 'Active']);
```

If an invalid enum value is provided, a helpful error message is shown:

```
Value "unknown" is not possible option of enum "Status". Did you mean "active", "inactive", "pending"?
```

## 🔍 Custom Entity Repository

For integrating with ORMs like Doctrine, implement the `ProjectEntityRepository` interface:

```php
use Baraja\ServiceMethodInvoker\ProjectEntityRepository;

class DoctrineEntityRepository implements ProjectEntityRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function find(string $className, int|string $id): ?object
    {
        return $this->em->find($className, $id);
    }
}

$invoker = new ServiceMethodInvoker(
    projectEntityRepository: new DoctrineEntityRepository($entityManager),
);
```

This allows automatic entity resolution from IDs:

```php
class ArticleController
{
    public function show(Article $article): Response
    {
        // $article is automatically loaded from the database using the ID
    }
}

$invoker->invoke($controller, 'show', ['article' => 42]);
// Article with ID 42 is automatically loaded via the repository
```

## 📑 The Data Parameter

When `dataMustBeArray` is `true`, a parameter named `data` must be typed as `array` and will receive all input parameters:

```php
class BatchProcessor
{
    public function process(array $data): void
    {
        // $data contains all the input parameters
    }
}

$invoker->invoke($processor, 'process', [
    'item1' => 'value1',
    'item2' => 'value2',
], dataMustBeArray: true);

// $data = ['item1' => 'value1', 'item2' => 'value2']
```

## 🐛 Tracy Debugger Integration

When Tracy Debugger is available, the package automatically registers a custom BlueScreen panel that provides:

- **Service class name** and the exact method being called
- **Source code highlighting** of the failing method
- **Input parameters table** showing all passed values
- **Direct editor link** to open the file at the exact line

This integration is automatic when Tracy is installed - no configuration needed.

## ⚠️ Exception Handling

The `RuntimeInvokeException` provides rich context for debugging:

```php
try {
    $invoker->invoke($service, 'method', $params);
} catch (RuntimeInvokeException $e) {
    $e->getService();  // The service object that failed
    $e->getMethod();   // The method name that was being called
    $e->getParams();   // The parameters that were passed
    $e->getMessage();  // Detailed error message
}
```

### Common Exceptions

| Scenario | Exception Type |
|----------|---------------|
| Method doesn't exist | `InvalidArgumentException` |
| Method is not callable | `InvalidArgumentException` |
| Required parameter missing | `RuntimeInvokeException` |
| Invalid enum value | `RuntimeInvokeException` |
| Type incompatibility | `RuntimeInvokeException` |
| Circular reference in entities | `RuntimeInvokeException` |
| Entity class doesn't exist | `RuntimeInvokeException` |

## 🎨 Service Interface

Implement the `Service` interface on your services for better error messages:

```php
use Baraja\Service;

class UserApiEndpoint implements Service
{
    public function __toString(): string
    {
        return 'User API Endpoint';
    }

    public function getUser(int $id): array
    {
        // ...
    }
}

// Error messages will now show "User API Endpoint" instead of the class name
```

## 📋 DateTime Handling

`DateTimeInterface` implementations are automatically instantiated:

```php
class EventService
{
    public function schedule(
        string $name,
        \DateTimeImmutable $startDate,
    ): void {
        // $startDate is automatically created from the string value
    }
}

$invoker->invoke($service, 'schedule', [
    'name' => 'Conference',
    'startDate' => '2024-06-15 10:00:00',
]);
```

## 🔒 Default Values for Empty Inputs

When a non-nullable parameter receives an empty value, appropriate defaults are used:

| Type | Default Value |
|------|---------------|
| `string` | `""` (empty string) |
| `int` | `0` |
| `float` | `0.0` |
| `bool` | `false` |
| `array` | `[]` |

## 👤 Author

**Jan Barasek** - [https://baraja.cz](https://baraja.cz)

## 📄 License

`baraja-core/service-method-invoker` is licensed under the MIT license. See the [LICENSE](https://github.com/baraja-core/service-method-invoker/blob/master/LICENSE) file for more details.
