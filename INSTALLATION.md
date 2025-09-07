# Mondu Payment Plugin - Complete Installation & Configuration Guide

## 📋 Overview

This plugin provides Mondu payment integration for Shopware 6.7+ with features:
- Multiple payment methods (Invoice, Installments, SEPA, Pay Now)
- API credentials validation with interactive button
- Webhook support for order status updates
- Admin interface for order management
- CLI commands for automation

## 🔧 Requirements

- Shopware 6.7+
- PHP 8.1+
- Node.js 16+ and npm
- Composer
- Mondu merchant account with API credentials

## 🚀 Quick Installation

### Option 1: Automated Installation (Recommended)

```bash
# From Shopware root directory
./custom/plugins/Mond1SW6/install.sh
```

### Option 2: Manual Installation

#### Step 1: Clone the Plugin
```bash
git clone <repository-url> custom/plugins/Mond1SW6
```

#### Step 2: Install Dependencies
```bash
# Install Node.js dependencies
cd custom/plugins/Mond1SW6/src/Resources/app/administration
npm install

# Return to Shopware root
cd ../../../../..

# Update Composer autoload
composer dump-autoload
```

#### Step 3: Activate Plugin
```bash
# Update plugin configuration
bin/console bundle:dump

# Install and activate plugin
bin/console plugin:install --activate Mond1SW6

# Clear cache
bin/console cache:clear
```

#### Step 4: Build Administration Assets
```bash
# Build all administration assets (includes Mondu)
./bin/build-administration.sh
```

## ✅ Verification

After installation, verify that:

1. **Plugin is active:**
   ```bash
   bin/console plugin:list | grep Mond1SW6
   ```

2. **JavaScript files are built:**
   ```bash
   ls -la public/bundles/mond1sw6/administration/.vite/
   ```
   Should contain:
   - `entrypoints.json`
   - `manifest.json` 
   - `assets/mond1-s-w6-[HASH].js`

3. **Validation button works:**
   - Go to Administration > Extensions > My Extensions > Mondu Payment > Configure
   - Look for "Validate API Credentials" button under API Key field
   - Button should trigger API validation

## 📦 Alternative Installation Methods

### Option 3: Install via Shopware Extensions (ZIP Upload)

1. **Upload Extension:**
   - Navigate to Shopware Administration > Extensions > My Extensions
   - Click "Upload Extension"
   - Upload the provided .zip file
   - Click "Install" next to the Mondu Payment extension

2. **Activate Extension:**
   - Click "Activate" button to activate the extension

3. **Build Administration Assets:**
   ```bash
   # After ZIP installation, you still need to build assets
   ./bin/build-administration.sh
   ```

## 🔧 Configuration

### Step 1: Plugin Configuration
1. Navigate to Administration > Extensions > My Extensions > Mondu Payment
2. Click three dots and select "Configure"
3. Configure the following:
   - **API Key**: Your Mondu API token
   - **Test mode**: Enable for sandbox environment (recommended for testing)
   - **Webhook URL**: Set up webhook endpoint for order updates
4. **Test API Credentials:**
   - Use the "Validate API Credentials" button
   - Button automatically detects sandbox/live mode
   - Shows success/error messages for validation results
5. Click "Save"

### Step 2: Activate Payment Method
1. Navigate to Settings > Payment Methods
2. Find "Mondu Payment" and click "Edit"
3. Check "Active" field to activate the payment
4. Configure additional settings if needed
5. Click "Save"

### Step 3: Add to Sales Channel
1. Navigate to Sales Channels > Storefront (or your default channel)
2. Go to "Payment & Shipping" tab
3. Add Mondu payment methods to "Payment methods"
4. Click "Save"

## 🖥️ CLI Commands

The plugin provides several CLI commands for automation:

### Test API Token
```bash
bin/console Mond1SW6:Test <api_token> <sandbox_mode> --no-debug
```
- Tests if API token is valid
- `<api_token>`: Your Mondu API token
- `<sandbox_mode>`: true/false for sandbox/live mode

### Configure API Token
```bash
bin/console Mond1SW6:Config:ApiToken <api_token> <sandbox_mode> --no-debug
```
- Sets API token in plugin configuration
- Useful for automated deployments

### Activate Payment Method
```bash
bin/console Mond1SW6:Activate:Payment --no-debug
```
- Automatically activates plugin as payment method in all StoreFront sales channels
- Useful for automated setup

## 🐛 Troubleshooting

### JavaScript Not Loading
If the validation button doesn't appear:

1. **Rebuild administration:**
   ```bash
   ./bin/build-administration.sh
   ```

2. **Check file permissions:**
   ```bash
   ls -la public/bundles/mond1sw6/administration/
   ```

3. **Verify entrypoints.json:**
   ```bash
   cat public/bundles/mond1sw6/administration/.vite/entrypoints.json
   ```

### Plugin Not Activating
```bash
# Check plugin status
bin/console plugin:list

# Reinstall if needed
bin/console plugin:uninstall Mond1SW6
bin/console plugin:install --activate Mond1SW6
```

### Build Errors
```bash
# Clear all caches
bin/console cache:clear

# Rebuild from scratch
rm -rf custom/plugins/Mond1SW6/src/Resources/app/administration/node_modules
cd custom/plugins/Mond1SW6/src/Resources/app/administration
npm install
cd ../../../../..
./bin/build-administration.sh
```

## 📁 File Structure

After successful installation:

```
custom/plugins/Mond1SW6/
├── install.sh                           # Installation script
├── src/Resources/app/administration/
│   ├── package.json                     # Dependencies (in git)
│   ├── vite.config.ts                   # Vite config (in git)
│   ├── src/main.ts                      # Main JS file (in git)
│   └── node_modules/                    # Generated (ignored)
└── src/Resources/public/                # Generated (ignored)

public/bundles/mond1sw6/administration/
├── .vite/
│   ├── entrypoints.json                 # Generated
│   └── manifest.json                    # Generated
└── assets/
    └── mond1-s-w6-[HASH].js            # Generated
```

## 🔄 Updating the Plugin

```bash
# Pull latest changes
git pull origin main

# Reinstall dependencies (if package.json changed)
cd custom/plugins/Mond1SW6/src/Resources/app/administration
npm install

# Rebuild
cd ../../../../..
./bin/build-administration.sh
bin/console cache:clear
```

## 🆘 Support

If you encounter issues:

1. Check the Shopware logs: `var/log/`
2. Check browser console for JavaScript errors
3. Verify all requirements are met
4. Try the troubleshooting steps above

---

**Note:** This plugin requires Shopware 6.7+ and uses Vite for asset compilation. The build process automatically generates hashed JavaScript files for proper caching.
