# wp-shkeeper-plugin
Shkeeper payment gateway plugin for CMS WordPress + WooCommerce

*Plugin has been tested on CMS WordPress 5.9.3 + WooCommerce 6.3.1*

## Installation
### Upload via WordPress Admin

Download Shkeeper plugin as a zip file to your PC.
To upload Shkeeper plugin, log in to your WordPress dashboard.
1. Navigate to Plugins > Add New.
2. Click the Upload Plugin button at the top of the screen.
3. Select the shkeeper.zip file from your local filesystem.
4. Click the Install Now button.
5. When the installation is complete, you’ll see “Plugin installed successfully.” Click the Activate Plugin button.
   
Detailed instruction can be found official WordPress [site](https://wordpress.org/support/article/managing-plugins/#upload-via-wordpress-admin)
### Manual Plugin Installation

In rare cases, you may need to install a plugin by manually transferring the files onto the server. This is recommended only when absolutely necessary, for example when your server is not configured to allow automatic installations.

This procedure requires you to be familiar with the process of transferring files using an SFTP client. It is recommended for advanced users and developers.

Detailed instructions can be found on official WordPress [site](https://wordpress.org/support/article/managing-plugins)

## Configuration

After successful installation you should configure plugin.
1. Go to WooCommerce > Settings > Payments and enable Shkeeper.
2. Click on Set up and configure the payment method. You will get the Shkeeper settings to be set.
3. Here, enable the Shkeeper and then enter the api key, api url and instructions with description for your customers, etc..
    * Enable/Disable – Disable the same to turn off and Enable the same to use.
    * Api key - Authorization and identification Shkeeper key. You can generate it in Shkeeper admin panel for any crypto wallet.
    * Api url - Shkeeper server api entry point. 
    * Title – The title to display to the customers on the Checkout page.
    * Description – The details to be shown to the customers, when they choose the Shkeeper option.
    * Instruction – Contains the explanation on how to pay by Shkeeper.
    * Enable for shipping methods - You can choose what shipping methods supports Shkeeper payments.
    * Accept for virtual orders - Disable or enable Skeeper for WooCommerce virtual orders.
    * Logging - Disable or enable Shkeeper plugin logging of communication with Shkeeper Api server.
4. Once done save the changes.

<p align="center">
  <img src="https://github.com/user-attachments/assets/33e77242-d823-40bf-9305-19ee1cd8ad1e" alt="photo_2024-08-12_10-25-03">
</p>

<p align="center">
  <img src="https://github.com/user-attachments/assets/1ca843ad-c015-4234-a511-8ff3eadedf43" alt="photo_2024-08-12_10-25-12">
</p>

<p align="center">
  <img src="https://github.com/user-attachments/assets/1887179e-31f8-4199-ad95-c4cb8addaefe" alt="photo_2024-08-12_10-25-17">
</p>

<p align="center">
  <img src="https://github.com/user-attachments/assets/1a174ceb-39b4-4e1b-9b45-9730d74634c3" alt="photo_2024-08-12_10-25-22">
</p>


