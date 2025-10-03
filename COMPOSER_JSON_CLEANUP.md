# Composer.json Cleanup - Removing Redundant File

## Problem
After implementing intelligent composer.json merging, the separate `composer.json` file in the startup directory became redundant and unnecessary.

## Solution Implemented

### ✅ **Removed Redundant File**
- **Deleted**: `src/startup/composer.json`
- **Reason**: No longer needed since we use hardcoded template configuration

### ✅ **Hardcoded Template Configuration**
- **Added**: `TEMPLATE_COMPOSER_CONFIG` constant
- **Contains**: All the configuration that was previously in the startup composer.json
- **Benefits**: 
  - No external file dependency
  - Version controlled in code
  - Easier to maintain and update
  - No risk of file corruption or missing files

### ✅ **Simplified Code Structure**
- **Removed**: `SKIP_FILES` constant (no longer needed)
- **Removed**: `handleSpecialFile()` method (no longer needed)
- **Simplified**: `copyTemplateFiles()` method
- **Updated**: `mergeComposerJson()` to use hardcoded config

## Code Changes

### Before (File-based approach):
```php
// Read template composer.json from file
$templateContent = file_get_contents($templateComposerPath);
$templateComposer = json_decode($templateContent, true);
```

### After (Hardcoded approach):
```php
// Use hardcoded template configuration
$templateComposer = self::TEMPLATE_COMPOSER_CONFIG;
```

## Benefits

### 🚀 **Performance**
- **Faster execution** - No file I/O for template config
- **Reduced dependencies** - No external file required
- **Better reliability** - No risk of missing or corrupted files

### 🛠️ **Maintainability**
- **Version controlled** - Template config is in source code
- **Easier updates** - Change constant instead of file
- **No file management** - One less file to maintain
- **Clearer code** - Configuration is visible in the class

### 🔒 **Reliability**
- **No file system dependencies** - Works even if startup directory is incomplete
- **Consistent behavior** - Always uses the same template configuration
- **Error prevention** - No JSON parsing errors from template file

## Template Configuration

The hardcoded template includes:
```php
private const TEMPLATE_COMPOSER_CONFIG = [
    'name' => 'my_app/v1',
    'description' => 'Super light Framework based on Gemvc swoole',
    'type' => 'library',
    'require' => [
        'symfony/dotenv' => '^7.0'
    ],
    'require-dev' => [
        'phpstan/phpstan' => '^1.10'
    ],
    'license' => 'Apache-2.0',
    'autoload' => [
        'psr-4' => [
            'App\\Api\\' => 'app/api/',
            'App\\Controller\\' => 'app/controller/',
            'App\\Model\\' => 'app/model/',
            'App\\Table\\' => 'app/table/'
        ]
    ],
    'scripts' => [
        'phpstan' => 'phpstan analyse',
        'phpstan:baseline' => 'phpstan analyse --generate-baseline'
    ],
    'authors' => [
        [
            'name' => 'Ali Khorsandfard',
            'email' => 'ali.khorsandfard@gmail.com'
        ]
    ],
    'minimum-stability' => 'stable'
];
```

## Files Modified

### ✅ **Deleted**
- `src/startup/composer.json` - No longer needed

### ✅ **Updated**
- `src/CLI/commands/InitProject.php`
  - Added `TEMPLATE_COMPOSER_CONFIG` constant
  - Removed `SKIP_FILES` constant
  - Removed `handleSpecialFile()` method
  - Simplified `copyTemplateFiles()` method
  - Updated `mergeComposerJson()` to use hardcoded config

## Result

- **Cleaner codebase** - One less file to maintain
- **Better performance** - No file I/O for template config
- **Improved reliability** - No external file dependencies
- **Easier maintenance** - Template config is version controlled
- **Same functionality** - All merging behavior preserved

## Backward Compatibility

✅ **Fully backward compatible** - No changes to external API or behavior.

The project initialization works exactly the same way, but now with a cleaner, more maintainable implementation.
