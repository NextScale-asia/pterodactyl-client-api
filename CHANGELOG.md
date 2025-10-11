# Changelog

All notable changes to `pterodactyl-client-api` will be documented in this file.

## [Unreleased]

## [1.0.0] - 2025-10-11

### Added
- Initial release of Pterodactyl Client API package
- User API key management endpoints
- Free allocation listing functionality
- User ownership validation middleware
- Comprehensive input validation
- Rate limiting (max 5 API keys per user)
- Complete audit logging
- Config file support
- Laravel auto-discovery support

### Features
- GET `/api/application/users/{user}/api-keys` - List user API keys
- POST `/api/application/users/{user}/api-keys` - Create new API key
- DELETE `/api/application/users/{user}/api-keys/{identifier}` - Delete API key
- GET `/api/application/nodes/{node}/allocations/free` - List free allocations

### Security
- ACL integration with Pterodactyl's permission system
- User ownership middleware to prevent unauthorized access
- IP restriction support for API keys
- Comprehensive input validation and sanitization