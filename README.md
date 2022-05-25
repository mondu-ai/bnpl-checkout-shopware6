### Installation

##### Install using Shopware 6 Extensions

1. Navigate to the Shopware Administration -> Extensions -> My Extensions
2. Click on "Upload Extension"
3. Upload provided .zip file
4. Click Install next to the Mondu Payment extension
5. Click on Activate button to activate the extension
6. Click on three dots next to the Mondu Payment extension and select Configure
   1. Select Sandbox mode
   2. Enter API key and Webhooks Secret
   3. Save
7. Navigate to the Settings -> Payment and click on Edit Mondu Payment
   1. Select Active field to activate the payment
   2. Save
8. Navigate to the Storefront sales channel (or any default)
   1. Add Mondu payment as a Payment method
   2. Click Save

### Development with Dockware.io

##### Set up development environment

1. Follow instructions to create #dev shopware store on [https://dockware.io/getstarted#dev]()
2. Connect your code editor to the running docker image with SFTP (example for vs code sftp below)

   ```
   {
       "name": "Dockware",
       "host": "localhost",
       "protocol": "sftp",
       "port": 22,
       "username": "dockware",
       "password": "dockware",
       "remotePath": "/var/www/html",
       "uploadOnSave": true
   }
   ```
3. Clone the plugin in the custom/plugins folder
4. Plugin should be available in My Extensions page

##### Set up #play demo store

This demo store is created for quick testing of plugins, themes, etc. and it can't be used for development.

1. Start Dockware container by running

   ```
   docker run --rm -p 80:80 dockware/play:latest
   ```
2. Wait until you see further instructions and URLs.
3. Navigate to the shop administration http://localhost/admin and login with default credentials:

   ```
   Username: admin
   Password: shopware
   ```
