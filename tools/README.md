# Silo License Tools

This directory contains tools for managing Silo licenses. **Keep this directory secure and do not distribute it with the application.**

## Files

- `generate-license.php` - License key generator script
- `license-private.pem` - RSA private key (generated, keep secret!)
- `license-public.pem` - RSA public key (generated, add to Silo settings)

## Setup

1. Generate RSA key pair:
   ```bash
   php generate-license.php --generate-keys
   ```

2. Add the public key to your Silo instance:
   - Go to Admin > Settings
   - Add a setting `license_public_key` with the public key content
   - Or directly insert into database:
   ```sql
   INSERT INTO settings (key, value) VALUES ('license_public_key', '-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----');
   ```

## Generating Licenses

### Pro License (1 year)
```bash
php generate-license.php --tier=pro --email=customer@example.com
```

### Business License (1 year)
```bash
php generate-license.php --tier=business --email=company@example.com
```

### Lifetime License
```bash
php generate-license.php --tier=pro --email=customer@example.com --expires=never
```

### Custom Expiration
```bash
php generate-license.php --tier=pro --email=customer@example.com --expires=2026-12-31
```

### With Customer Name
```bash
php generate-license.php --tier=business --email=company@example.com --name="Acme Corp"
```

## License Tiers

### Community (Free)
- 1 user
- 100 models
- 5 GB storage
- Basic features only

### Pro ($X/year)
- 5 users
- Unlimited models
- 100 GB storage
- All productivity features (tags, favorites, batch operations, print queue, themes, etc.)

### Business ($X/year)
- Unlimited users
- Unlimited models
- Unlimited storage
- All Pro features plus: API access, SSO/OIDC, webhooks, custom branding, priority support

## Security Notes

1. **Never share the private key** (`license-private.pem`)
2. **Don't commit this directory** to version control
3. **Keep backups** of your private key in a secure location
4. Generated license files contain sensitive customer information

## .gitignore

Add these to your .gitignore:
```
tools/*.pem
tools/license-*.txt
```
