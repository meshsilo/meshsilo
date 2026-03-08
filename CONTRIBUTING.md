# Contributing to MeshSilo

Thank you for your interest in contributing to MeshSilo! This document provides guidelines and instructions for contributing.

## Code of Conduct

Be respectful and constructive in all interactions. We welcome contributors of all experience levels.

## How to Contribute

### Reporting Bugs

1. Check existing issues to avoid duplicates
2. Use the bug report template
3. Include:
   - MeshSilo version
   - PHP version
   - Database type (SQLite/MySQL)
   - Steps to reproduce
   - Expected vs actual behavior
   - Error messages or logs

### Suggesting Features

1. Check existing issues and discussions
2. Describe the use case and problem it solves
3. Consider implementation implications

### Submitting Code

1. Fork the repository
2. Create a feature branch from `main`
3. Make your changes
4. Write/update tests as needed
5. Ensure all tests pass
6. Submit a pull request

## Development Setup

### Prerequisites

- PHP 8.1 or higher
- Composer
- SQLite or MySQL
- Git

### Local Development

```bash
# Clone your fork
git clone https://github.com/YOUR_USERNAME/MeshSilo.git
cd MeshSilo

# Install dependencies
composer install

# Start development server
php -S localhost:8000

# Run tests
composer test

# Run static analysis
composer analyse

# Check code style
composer cs
```

### Docker Development

```bash
docker compose up -d --build
docker compose logs -f
```

## Code Style

- Follow PSR-12 coding standards
- Use meaningful variable and function names
- Add comments for complex logic
- Keep functions focused and small

### PHP

```php
// Good
function getUserById(int $id): ?User
{
    return User::find($id);
}

// Avoid
function get($id)
{
    // ...
}
```

### JavaScript

- Use modern ES6+ syntax
- Avoid global variables
- Document public functions

### CSS

- Use CSS custom properties (variables)
- Follow existing naming conventions
- Mobile-first responsive design

## Testing

### Running Tests

```bash
# All tests
composer test

# With coverage
composer test:coverage

# Specific test file
./vendor/bin/phpunit tests/Unit/ExampleTest.php
```

### Writing Tests

- Place tests in `tests/` directory
- Name test files with `Test` suffix
- Test both success and failure cases
- Mock external dependencies

## Pull Request Process

1. **Branch Naming**: Use descriptive names
   - `feature/add-bulk-export`
   - `fix/upload-validation-error`
   - `docs/api-authentication`

2. **Commit Messages**: Be clear and concise
   - `Add bulk export functionality for collections`
   - `Fix file upload validation for large files`
   - `Update API authentication documentation`

3. **PR Description**: Include
   - What changes were made
   - Why the changes are needed
   - How to test the changes
   - Screenshots for UI changes

4. **Review Process**
   - Address reviewer feedback
   - Keep PR scope focused
   - Squash commits if requested

## Documentation

- Update README.md for user-facing changes
- Update CLAUDE.md for developer documentation
- Add inline comments for complex code
- Update API documentation for endpoint changes

## Questions?

- Open a GitHub Discussion for questions
- Check existing documentation first
- Be patient - maintainers are volunteers

## License

By contributing, you agree that your contributions will be licensed under the GNU Affero General Public License v3.0 (AGPL-3.0).
