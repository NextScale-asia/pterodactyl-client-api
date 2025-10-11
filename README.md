# Pterodactyl Client API

[![Latest Version on Packagist](https://img.shields.io/packagist/v/byzic/pterodactyl-client-api.svg?style=flat-square)](https://packagist.org/packages/byzic/pterodactyl-client-api)
[![Total Downloads](https://img.shields.io/packagist/dt/byzic/pterodactyl-client-api.svg?style=flat-square)](https://packagist.org/packages/byzic/pterodactyl-client-api)

This package provides Application API endpoints for managing user API keys and free allocations in Pterodactyl Panel.

## Features

- ✅ **User API Key Management**: Allow users to manage their own API keys through application API endpoints
- ✅ **Free Allocation Listing**: Provide endpoints to list available free allocations on nodes
- ✅ **User Ownership Validation**: Middleware to ensure users can only access their own resources
- ✅ **Rate Limiting**: Max 5 API keys per user with proper validation
- ✅ **Input Validation**: Unique description per user with comprehensive validation
- ✅ **Audit Logging**: Complete audit logging for all operations
- ✅ **Easy Integration**: Simple installation via Composer


## List Features
- ✅ Create API keys for users via Application API
- ✅ List user API keys with proper filtering
- ✅ Delete specific user API keys
- ✅ Comprehensive permission system (Admin ACL)
- ✅ User ownership validation middleware
- ✅ Rate limiting (max 5 API keys per user)
- ✅ Input validation with unique description per user
- ✅ Complete audit logging for all operations
- ✅ Proper error handling and responses


## Installation

You can install the package via composer:

The service provider will be automatically registered. You can publish the config file with:

```bash
php artisan vendor:publish --provider="Byzic\PterodactylClientApi\PterodactylApiAddonServiceProvider" --tag="config"
```

This will publish the configuration file to `config/pterodactyl-client-api.php`.

## API Endpoints

### User API Keys

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/application/users/{user}/api-keys` | Get user's API keys |
| POST | `/api/application/users/{user}/api-keys` | Create new API key for user |
| DELETE | `/api/application/users/{user}/api-keys/{identifier}` | Delete user's API key |

### Free Allocations

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/application/nodes/{node}/allocations/free` | Get free allocations for a node |

## Usage Examples

### Get User API Keys

```bash
curl -X GET "https://your-panel.com/api/application/users/1/api-keys" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json"
```

### Create User API Key

```bash
curl -X POST "https://your-panel.com/api/application/users/1/api-keys" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "My API Key",
    "allowed_ips": ["127.0.0.1"]
  }'
```

### Delete User API Key

```bash
curl -X DELETE "https://your-panel.com/api/application/users/1/api-keys/key_identifier" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

```bash
curl -X GET "https://your-panel.com/api/application/nodes/1/allocations/free" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json"
```

## Configuration

After publishing the config file, you can modify the settings in `config/pterodactyl-client-api.php`:

```php
return [
    // API Key settings
    'api_key' => [
        'max_keys_per_user' => 10,
        'default_permissions' => [
            'user.read',
            'server.read',
        ],
    ],
    
    // Free allocation settings
    'allocations' => [
        'show_free_only' => true,
        'include_node_info' => true,
    ],
];
```

## Security Features

- **ACL Integration**: Uses Pterodactyl's built-in Admin ACL system
- **User Ownership Validation**: Middleware ensures users can only access their own resources
- **Rate Limiting**: Maximum 5 API keys per user (configurable)
- **Input Validation**: Comprehensive validation with custom error messages
- **Audit Logging**: All operations are logged for security tracking
- **IP Restrictions**: Support for limiting API key usage by IP address

## Requirements

- PHP ^8.0|^8.1|^8.2|^8.3
- Laravel ^9.0|^10.0|^11.0
- Pterodactyl Panel

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [Byzic](https://github.com/byzic)
- [All Contributors](../../contributors)

## Support

If you discover any security vulnerabilities, please send an e-mail to byzic@example.com.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.
    "errors": [
        {
            "code": "ValidationException",
            "status": "422",
            "detail": "You already have an API key with this description."
        }
    ]
}
```

## Changelog

### v2.0.0
- ✅ Fixed API key creation logic to use Pterodactyl's native system
- ✅ Added comprehensive audit logging
- ✅ Implemented user ownership validation middleware
- ✅ Added unique description validation per user
- ✅ Removed unrelated FileController functionality
- ✅ Improved error handling and validation
- ✅ Added rate limiting (5 keys per user)
- ✅ Enhanced security with proper ACL integration

## License

MIT License