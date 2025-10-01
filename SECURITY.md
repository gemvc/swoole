# Security Policy

## 🔒 GEMVC OpenSwoole Security Overview

GEMVC OpenSwoole is designed with security as a core principle. This document outlines the security features, best practices, and guidelines for secure development and deployment.

---

## 🛡️ Built-in Security Features

### 1. Request Security Layer

#### Path Protection
GEMVC automatically blocks direct access to sensitive directories and files:

**Blocked Directories:**
- `/app` - Application code
- `/vendor` - Composer dependencies
- `/bin` - Executable files
- `/templates` - Template files
- `/config` - Configuration files
- `/logs` - Log files
- `/storage` - Storage files
- `/.env` - Environment variables
- `/.git` - Version control

**Blocked File Extensions:**
- `.php`, `.env`, `.ini`, `.conf`, `.config`
- `.log`, `.sql`, `.db`, `.sqlite`
- `.md`, `.txt`, `.json`, `.xml`, `.yml`, `.yaml`

**Security Response:**
```json
{
    "error": "Access Denied",
    "message": "Direct file access is not permitted"
}
```

#### URL Routing Security
- All requests must go through `index.php` in project root
- No direct file access allowed
- Centralized request processing through `SwooleBootstrap`
- Service and method validation before execution

### 2. Authentication & Authorization

#### JWT Token System
GEMVC implements a robust JWT-based authentication system:

**Token Types:**
- **Access Tokens**: Short-lived (default: 20 minutes)
- **Refresh Tokens**: Long-lived (default: 12 hours)
- **Login Tokens**: Extended validity (default: 9 days)

**Token Features:**
- Secure token generation with configurable expiration
- Token verification and validation
- Role-based access control (RBAC)
- User, company, and branch context support
- Automatic token renewal

**Environment Configuration:**
```env
TOKEN_ISSUER='MyCompany'
TOKEN_SECRET='your-secret-key'
LOGIN_TOKEN_VALIDATION_IN_SECONDS=789000
REFRESH_TOKEN_VALIDATION_IN_SECONDS=43200
ACCESS_TOKEN_VALIDATION_IN_SECONDS=1200
```

#### Password Security
- **Argon2i Hashing**: Industry-standard password hashing
- **Password Verification**: Secure password validation
- **No Plain Text Storage**: Passwords are never stored in plain text

```php
// Password hashing
$hashedPassword = CryptHelper::hashPassword($password);

// Password verification
$isValid = CryptHelper::passwordVerify($password, $hashedPassword);
```

### 3. Data Security

#### Database Security
- **Prepared Statements**: All queries use parameterized statements
- **SQL Injection Prevention**: Built-in protection against SQL injection
- **Connection Pooling**: Secure connection management
- **Error Handling**: Sensitive information not exposed in errors

#### Encryption Support
- **AES-256-CBC Encryption**: For sensitive data encryption
- **SHA-256 Hashing**: For key derivation
- **Base64 Encoding**: For safe data transmission

```php
// Data encryption
$encrypted = CryptHelper::crypt($data, $secret, $iv, 'e', 'AES-256-CBC');

// Data decryption
$decrypted = CryptHelper::crypt($encrypted, $secret, $iv, 'd', 'AES-256-CBC');
```

### 4. OpenSwoole Security

#### Process Security
- **Worker Process Isolation**: Each request handled in isolated worker
- **Memory Management**: Automatic memory cleanup
- **Connection Limits**: Configurable connection limits
- **Request Limits**: Maximum requests per worker

#### Server Configuration
```php
// Secure OpenSwoole configuration
$server->set([
    'worker_num' => 3,                    // Number of worker processes
    'max_request' => 5000,                // Max requests per worker
    'max_conn' => 1024,                   // Max connections
    'enable_coroutine' => true,           // Enable coroutines
    'heartbeat_idle_time' => 600,         // Connection timeout
    'reload_async' => true                // Graceful reload
]);
```

---

## 🔐 Security Best Practices

### 1. Environment Security

#### Environment Variables
- **Never commit `.env` files** to version control
- **Use strong secrets** for JWT tokens and encryption
- **Rotate secrets regularly** in production
- **Use different secrets** for different environments

#### Recommended .env Security:
```env
# Strong, unique secrets
TOKEN_SECRET='your-very-long-random-secret-key-here'
DB_PASSWORD='strong-database-password'

# Environment-specific settings
APP_ENV=production
SWOOLE_DISPLAY_ERRORS=0
```

### 2. Database Security

#### Connection Security
- **Use strong passwords** for database connections
- **Enable SSL/TLS** for database connections in production
- **Limit database user permissions** to minimum required
- **Use connection pooling** to prevent connection exhaustion

#### Query Security
- **Always use prepared statements** (automatically handled by GEMVC)
- **Validate all input data** before database operations
- **Implement proper error handling** without exposing sensitive information

### 3. API Security

#### Request Validation
- **Validate all input parameters** in your API services
- **Implement rate limiting** for API endpoints
- **Use HTTPS** in production environments
- **Implement proper CORS** policies if needed

#### Response Security
- **Never expose sensitive data** in API responses
- **Use appropriate HTTP status codes**
- **Implement proper error handling**
- **Log security events** for monitoring

### 4. Authentication Security

#### Token Management
- **Use short-lived access tokens** (15-30 minutes)
- **Implement token refresh** mechanism
- **Store tokens securely** on client side
- **Implement token revocation** for security incidents

#### User Management
- **Implement strong password policies**
- **Use multi-factor authentication** when possible
- **Implement account lockout** after failed attempts
- **Regular security audits** of user accounts

---

## 🚨 Security Monitoring

### 1. Logging

#### Security Events
GEMVC automatically logs security events:
- **Blocked access attempts** to sensitive paths
- **Authentication failures**
- **Authorization violations**
- **Database errors** (without sensitive data)

#### Log Configuration
```php
// Enable security logging
SWOOLE_SERVER_LOG_INFO=1

// Log levels
SWOOLE_LOG_ERROR    // Errors only
SWOOLE_LOG_INFO     // Errors + Info
```

### 2. Error Handling

#### Secure Error Responses
- **No sensitive information** in error messages
- **Consistent error format** across all endpoints
- **Proper HTTP status codes**
- **Error logging** for debugging

#### Example Error Response:
```json
{
    "error": "Internal Server Error",
    "message": "An error occurred while processing your request"
}
```

---

## 🔧 Security Configuration

### 1. Production Security Checklist

- [ ] **Environment Variables**: All secrets properly configured
- [ ] **Database Security**: Strong passwords and SSL enabled
- [ ] **HTTPS**: SSL/TLS certificates properly configured
- [ ] **Error Display**: Disabled in production (`SWOOLE_DISPLAY_ERRORS=0`)
- [ ] **Logging**: Security events properly logged
- [ ] **Token Secrets**: Strong, unique secrets for JWT
- [ ] **Database Access**: Limited user permissions
- [ ] **File Permissions**: Proper file system permissions
- [ ] **Firewall**: Appropriate firewall rules
- [ ] **Updates**: Regular security updates applied

### 2. Development Security

- [ ] **Local Environment**: Use development-specific secrets
- [ ] **Debug Mode**: Enable only in development
- [ ] **Test Data**: Use non-sensitive test data
- [ ] **Version Control**: Never commit secrets
- [ ] **Code Review**: Security-focused code reviews

---

## 🆘 Security Incident Response

### 1. Immediate Actions

1. **Identify the scope** of the security incident
2. **Isolate affected systems** if necessary
3. **Revoke compromised tokens** immediately
4. **Change all secrets** if compromised
5. **Notify relevant stakeholders**

### 2. Investigation

1. **Review security logs** for suspicious activity
2. **Analyze attack vectors** and methods used
3. **Document findings** for future prevention
4. **Implement additional security measures**

### 3. Recovery

1. **Patch vulnerabilities** identified
2. **Update security policies** if needed
3. **Monitor for continued threats**
4. **Conduct security audit** of entire system

---

## 📞 Security Support

### Reporting Security Issues

If you discover a security vulnerability in GEMVC OpenSwoole:

1. **DO NOT** create public issues for security vulnerabilities
2. **Email** security concerns to: [security@yourcompany.com]
3. **Include** detailed information about the vulnerability
4. **Wait** for response before public disclosure

### Security Updates

- **Subscribe** to security notifications
- **Update regularly** to latest versions
- **Monitor** security advisories
- **Test** updates in development first

---

## 📚 Additional Resources

### Security Documentation
- [OpenSwoole Security Guide](https://openswoole.com/docs)
- [JWT Security Best Practices](https://tools.ietf.org/html/rfc8725)
- [OWASP Security Guidelines](https://owasp.org/)

### Security Tools
- **Static Analysis**: PHPStan for code analysis
- **Dependency Scanning**: Composer security audit
- **Penetration Testing**: Regular security testing
- **Monitoring**: Application security monitoring

---

## 🔄 Security Policy Updates

This security policy is regularly updated to reflect:
- New security features
- Emerging threats
- Best practice changes
- Framework updates

**Last Updated**: [Current Date]
**Version**: 1.0.0

---

*Remember: Security is a shared responsibility. Always follow security best practices and keep your GEMVC OpenSwoole installation updated.*
