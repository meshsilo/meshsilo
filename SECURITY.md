# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |

## Reporting a Vulnerability

We take the security of MeshSilo seriously. If you discover a security vulnerability, please report it responsibly.

### How to Report

1. **Do NOT** open a public GitHub issue for security vulnerabilities
2. Email security concerns to the maintainers privately
3. Include as much detail as possible:
   - Type of vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if any)

### What to Expect

- **Acknowledgment**: Within 48 hours of your report
- **Initial Assessment**: Within 7 days
- **Resolution Timeline**: Depends on severity, typically within 30 days for critical issues

### Severity Levels

- **Critical**: Remote code execution, SQL injection, authentication bypass
- **High**: Privilege escalation, sensitive data exposure
- **Medium**: Cross-site scripting (XSS), CSRF in sensitive actions
- **Low**: Information disclosure, minor configuration issues

## Security Best Practices

When deploying MeshSilo, follow these recommendations:

### Production Deployment

1. **Use HTTPS**: Always deploy behind a reverse proxy with TLS
2. **Change Default Credentials**: Update the admin password immediately after installation
3. **Restrict Network Access**: Limit database access to application servers only
4. **Regular Backups**: Maintain encrypted backups of `storage/assets` and `storage/db`

### Authentication

1. **Enable 2FA**: Require two-factor authentication for admin accounts
2. **Strong Passwords**: Enforce minimum password complexity
3. **Session Security**: Configure appropriate session timeouts
4. **API Key Management**: Rotate API keys regularly and revoke unused keys

### File Uploads

1. **Extension Whitelist**: Only allow necessary 3D file formats
2. **Size Limits**: Configure appropriate upload size limits
3. **Storage Isolation**: Keep uploaded files outside the web root
4. **Virus Scanning**: Consider integrating malware scanning for uploads

### Database

1. **Regular Updates**: Keep database software updated
2. **Secure Credentials**: Use strong, unique database passwords
3. **Backup Encryption**: Encrypt database backups at rest
4. **Access Logging**: Enable database access logging

### Monitoring

1. **Audit Logs**: Regularly review audit logs for suspicious activity
2. **Failed Logins**: Monitor for brute force attempts
3. **Error Logs**: Check application logs for security-related errors
4. **Update Notifications**: Enable update checking for security patches

## Security Features

MeshSilo includes several built-in security features:

- **CSRF Protection**: All forms include CSRF tokens
- **Rate Limiting**: Configurable rate limits for API and login attempts
- **Security Headers**: Configurable HTTP security headers (CSP, X-Frame-Options, HSTS, etc.)
- **Input Validation**: Server-side validation of all user input
- **Prepared Statements**: PDO prepared statements for database queries
- **Session Security**: Secure session configuration with HttpOnly cookies
- **Audit Trail**: Comprehensive logging of security-relevant actions
- **Two-Factor Authentication**: TOTP-based 2FA for additional account security

## Acknowledgments

We appreciate security researchers who help keep MeshSilo safe. Contributors will be acknowledged (with permission) in release notes.
