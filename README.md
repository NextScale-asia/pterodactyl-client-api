# Pterodactyl User API Key Management Addon

This addon provides Application API endpoints for managing user API keys in Pterodactyl Panel.

## Features

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

1. Place the addon files in your Pterodactyl installation
2. Register the service provider in your application
3. Run `composer dump-autoload` to refresh autoloader

## API Endpoints

### Get User API Keys
```
GET /api/application/users/{user}/api-keys
```

### Create User API Key
```
POST /api/application/users/{user}/api-keys
Content-Type: application/json

{
    "description": "My API Key",
    "allowed_ips": ["192.168.1.1", "10.0.0.1"]  // Optional
}
```

### Delete User API Key
```
DELETE /api/application/users/{user}/api-keys/{identifier}
```

### Get Free Node Allocations
```
GET /api/application/nodes/{node}/allocations/free
```

## Security Features

- **ACL Integration**: Uses Pterodactyl's built-in Admin ACL system
- **User Ownership**: Users can only manage their own API keys (unless admin)
- **Rate Limiting**: Maximum 5 API keys per user
- **Input Validation**: Comprehensive validation with custom error messages
- **Audit Logging**: All operations are logged for security tracking
- **IP Restrictions**: Support for limiting API key usage by IP address

## Permissions Required

- **READ**: To view user API keys
- **WRITE**: To create new API keys
- **DELETE**: To remove API keys

## Error Responses

The addon follows Pterodactyl's standard error response format:

```json
{
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