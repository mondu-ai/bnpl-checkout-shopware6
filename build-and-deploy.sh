#!/bin/bash

# Build the plugin
cd src/Resources/app/administration
npm run build

# Files are built directly to assets folder with correct hash name
cd ../../../../..
echo "Files built directly to: public/bundles/mond1sw6/administration/assets/"

echo "✅ Plugin built and deployed successfully!"
