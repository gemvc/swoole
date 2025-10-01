# GEMVC OpenSwoole v0.1.0-pre

## 🚀 First Pre-Release - Ready for Testing

This is the first pre-release of the GEMVC OpenSwoole framework, a high-performance PHP microservices framework built on OpenSwoole. This release includes core functionality and is ready for community testing and feedback.

## ✨ What's New

### Core Framework Features
- **OpenSwoole Server Integration** - High-performance asynchronous server with hot reload support
- **Redis Manager** - Complete Redis integration with connection pooling and singleton pattern
- **Database Layer** - Advanced query builder with prepared statements, connection pooling, and schema management
- **Security Manager** - JWT authentication, input sanitization, and security utilities
- **Hot Reload Manager** - Development-friendly auto-reload functionality for faster development cycles

### CLI Tools & Commands
- **Project Initialization** - `gemvc init` command for setting up new projects with templates
- **Code Generation** - CRUD generators for controllers, models, tables, and services
- **Database Management** - Migration tools, schema generation, and database operations
- **Global Command Access** - System-wide CLI access with automatic PATH setup

### Development Experience
- **Template System** - Pre-built templates for rapid microservice development
- **Testing Integration** - Support for PHPUnit and Pest testing frameworks
- **Static Analysis** - PHPStan integration for code quality and type safety
- **Docker Support** - Containerized development environment with docker-compose
- **Environment Management** - Comprehensive .env configuration system

### Architecture Highlights
- **Microservices Ready** - Built specifically for microservice architectures
- **High Performance** - Leverages OpenSwoole's asynchronous capabilities
- **Security First** - Multi-layered security with SQL injection prevention and XSS protection
- **AI-Ready** - Machine-readable manifests for AI assistant integration

## 🛠️ Installation

```bash
# Install the pre-release version
composer require gemvc/swoole:0.1.0-pre

# Initialize a new project
gemvc init

# Start development server
php app/api/index.php
```

## 🚀 Quick Start

```bash
# Create new project
gemvc init

# Install dependencies
composer update

# Run tests (if testing framework was installed)
composer test

# Start development server
php app/api/index.php
```

## 📁 Project Structure

After running `gemvc init`, you'll get:

```
your-project/
├── app/
│   ├── api/           # API endpoints
│   ├── controller/    # Controllers
│   ├── model/         # Models
│   └── table/         # Database tables
├── bin/               # Executable scripts
├── templates/         # Code generation templates
├── .env              # Environment configuration
├── composer.json     # Dependencies
└── docker-compose.yml # Container setup
```

## ⚠️ Pre-Release Notice

This is a pre-release version intended for testing and feedback. While core functionality is implemented and tested, some features may still be refined based on community input before the stable 1.0.0 release.

## 🔧 System Requirements

- **PHP**: 8.1 or higher
- **Extensions**: OpenSwoole, Redis (optional)
- **Tools**: Composer
- **OS**: Linux, macOS, Windows (with WSL recommended)

## 🐛 Known Issues

- Some CLI commands may require additional configuration on Windows
- Redis connection pooling needs testing under high load
- Documentation is still being expanded

## 📝 What's Coming in 1.0.0

- Performance optimizations and benchmarking
- Additional middleware support
- Enhanced documentation and tutorials
- More code generation templates
- Production deployment guides
- WebSocket support
- Advanced caching strategies

## 🤝 Contributing

We welcome feedback and contributions! Please:

- Report issues on GitHub
- Suggest improvements and new features
- Test the framework with your use cases
- Share your experience and feedback

Your input will help us prepare for the stable 1.0.0 release.

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

---

**Version**: v0.1.0-pre  
**Release Date**: December 2024  
**Author**: Ali Khorsandfard  
**Email**: ali.khorsandfard@gmail.com  
**License**: MIT

---

## Changelog

### v0.1.0-pre (2025-10-01)
- Initial pre-release
- Core OpenSwoole server integration
- Redis manager with connection pooling
- Database layer with query builder
- Security manager with JWT support
- CLI tools and project initialization
- Hot reload manager for development
- Template system for code generation
- Testing framework integration
- Docker support
- PHPStan static analysis integration
