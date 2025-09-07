#!/bin/bash

# Mondu Payment Plugin Installation Script for Shopware 6.7
# This script automates the complete installation process

set -e  # Exit on any error

echo "🚀 Starting Mondu Payment Plugin Installation..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# Check if we're in Shopware root
if [ ! -f "bin/console" ]; then
    print_error "Please run this script from Shopware root directory"
    exit 1
fi

# Check if plugin exists
if [ ! -d "custom/plugins/Mond1SW6" ]; then
    print_error "Mond1SW6 plugin not found in custom/plugins/"
    exit 1
fi

print_status "Found Mond1SW6 plugin"

# Step 1: Install Node.js dependencies
print_status "Installing Node.js dependencies..."
cd custom/plugins/Mond1SW6/src/Resources/app/administration
npm install --prefer-offline
cd ../../../../..

# Step 2: Update Composer autoload
print_status "Updating Composer autoload..."
composer dump-autoload

# Step 3: Dump plugin configuration
print_status "Updating plugin configuration..."
bin/console bundle:dump

# Step 4: Install and activate plugin
print_status "Installing and activating plugin..."
if bin/console plugin:list | grep -q "Mond1SW6.*Yes.*Yes"; then
    print_warning "Plugin already installed and activated"
else
    bin/console plugin:install --activate Mond1SW6
fi

# Step 5: Build administration
print_status "Building administration assets..."
./bin/build-administration.sh

# Step 6: Clear cache
print_status "Clearing cache..."
bin/console cache:clear

# Step 7: Verify installation
print_status "Verifying installation..."

# Check if plugin is active
if bin/console plugin:list | grep -q "Mond1SW6.*Yes.*Yes"; then
    print_status "Plugin is installed and activated"
else
    print_error "Plugin installation failed"
    exit 1
fi

# Check if JS files are built
if [ -f "public/bundles/mond1sw6/administration/.vite/entrypoints.json" ]; then
    print_status "Administration assets built successfully"
else
    print_error "Administration assets build failed"
    exit 1
fi

# Check if API validation button works
if [ -f "public/bundles/mond1sw6/administration/assets/mond1-s-w6"*".js" ]; then
    print_status "JavaScript validation button ready"
else
    print_warning "JavaScript files not found - check build process"
fi

echo ""
print_status "🎉 Mondu Payment Plugin installed successfully!"
echo ""
echo "Next steps:"
echo "1. Configure API credentials in Administration > Extensions > Mondu Payment"
echo "2. Test API credentials using the 'Validate API Credentials' button"
echo "3. Configure payment methods and settings"
echo ""
print_warning "Note: Remember to configure your Mondu API tokens and webhook URLs"
