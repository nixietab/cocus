# Cocus 

The **Cocus Image Uploader** is a simple PHP script that allows users to upload images and manage their deletion based on user-defined criteria. This script supports two main options for image deletion:

1. **Time-Based Deletion**: The image will be deleted after a specified number of hours.
2. **View-Based Deletion**: The image will be deleted after being viewed once.

## Features

- **File Upload**: Users can upload images via a web form.
- **Deletion Options**:
  - **Time-Based**: Set a custom expiration time (up to 24 hours) after which the image will be deleted.
  - **View-Based**: The image will be deleted after the first view.

## Requirements

- PHP (>= 7.0)
- A web server (e.g., Apache, Nginx)

## File Structure

- `index.php`: The main PHP script that handles file uploads, image viewing, and periodic cleanup.
- `uploads/`: Directory where uploaded images are stored.

## General Troubleshooting

- Ensure the `uploads/` directory is writable by the web server.
- Check file permissions and PHP configuration if you encounter issues with file uploads.
