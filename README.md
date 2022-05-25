### Installation

##### Install using Shopware 6 Extensions

1. Navigate to the Shopware Administration -> Extensions -> My Extensions
2. Click on "Upload Plugin"
3. Upload plugin .zip file
4. Activate

### Development with Dockware.io

##### Set up development environment

1. Follow instructions to create #dev shopware store on [https://dockware.io/getstarted#dev]()
2. Connect your code editor to the running docker image with FTP (example for vs code sftp below)

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
