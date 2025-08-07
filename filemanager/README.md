# Secure PHP File Browser

A modern, secure file browser built with PHP and Bootstrap 5, designed with security as the top priority.

## Features

### 🔒 Security First
- **Directory Traversal Protection**: Prevents access to files outside the designated directory
- **Path Validation**: Uses `realpath()` and strict path checking
- **Secure Downloads**: All file downloads are processed through a secure handler
- **Input Sanitization**: Removes null bytes and normalizes paths

### 🎨 Modern Design
- **Bootstrap 5**: Professional, responsive design
- **Bootstrap Icons**: Intuitive file type icons
- **Mobile Friendly**: Works seamlessly on desktop and mobile devices
- **Clean Interface**: Minimal, easy-to-use design

### ⚡ Performance
- **Efficient Scanning**: Optimized directory reading
- **Smart File Handling**: Different strategies for small vs large files
- **Minimal Dependencies**: Only requires PHP and a web server

### 🧭 Navigation
- **Breadcrumb Navigation**: Easy way to navigate back through folders
- **Real-time Search**: Filter files as you type
- **Sorted Display**: Folders first, then files, all alphabetically sorted

## Installation

1. **Upload Files**: Copy the `filemanager` folder to your web server
2. **Set Permissions**: Ensure the web server can read the files
3. **Configure**: The `files/` directory is where you place your files
4. **Access**: Navigate to `yoursite.com/filemanager/` in your browser

## File Structure

```
filemanager/
├── index.php          # Main file browser interface
├── download.php       # Secure download handler
├── .htaccess         # Security configuration
├── files/            # Your files go here
│   ├── sample-document.txt
│   ├── project-info.json
│   └── reports/
│       └── Q1-report.md
└── README.md         # This file
```

## Security Measures

### Path Validation
- Uses `realpath()` to resolve symbolic links and relative paths
- Checks that resolved paths start with the allowed root directory
- Prevents access to files outside the `files/` directory

### Download Security
- All downloads go through `download.php`
- Validates file existence and permissions
- Sets appropriate headers to prevent XSS
- Sanitizes filenames for safe downloads

### Server Configuration
- `.htaccess` prevents directory listing
- Blocks direct access to sensitive files
- Sets security headers

## Usage

### Adding Files
1. Place your files in the `files/` directory
2. Create subdirectories as needed
3. Files will automatically appear in the browser

### Navigating
- Click folder names to enter directories
- Use breadcrumbs to navigate back
- Use the search box to filter files
- Click file names to download

### Supported File Types
The browser recognizes and provides appropriate icons for:
- **Documents**: PDF, Word, Excel, PowerPoint, Text
- **Images**: JPG, PNG, GIF, SVG, WebP
- **Archives**: ZIP, RAR, 7Z, TAR, GZ
- **Code**: PHP, HTML, CSS, JavaScript, Python, Java
- **Media**: MP3, MP4, AVI, WAV

## Customization

### Adding File Types
Edit the `getFileIcon()` function in `index.php` to add support for new file types:

```php
$iconMap = [
    'your_extension' => 'bi-your-icon-class',
    // ... existing mappings
];
```

### Styling
The interface uses Bootstrap 5 classes. You can:
- Modify the CSS in the `<style>` section of `index.php`
- Override Bootstrap styles
- Add custom themes

### Directory Configuration
Change the files directory by modifying the `FILE_ROOT` constant in both `index.php` and `download.php`:

```php
define('FILE_ROOT', __DIR__ . '/your-custom-directory');
```

## Advanced Features (Optional)

The current implementation includes basic search functionality. You can extend it with:

- **Sortable Columns**: Make table headers clickable to sort
- **File Upload**: Add upload functionality (requires additional security measures)
- **Image Previews**: Show image thumbnails or lightbox galleries
- **User Authentication**: Add login functionality
- **File Management**: Add rename, delete, move operations

## Security Best Practices

1. **Regular Updates**: Keep PHP and your web server updated
2. **File Permissions**: Use appropriate file system permissions
3. **Monitoring**: Monitor access logs for suspicious activity
4. **Backups**: Regular backups of your files
5. **Testing**: Test with various file types and paths

## Troubleshooting

### Files Not Showing
- Check file permissions
- Ensure files are in the correct directory
- Verify web server has read access

### Download Issues
- Check that `download.php` is accessible
- Verify file permissions
- Check for PHP error logs

### Permission Errors
- Ensure the web server user has read access to files
- Check directory permissions (755 recommended)
- Verify file permissions (644 recommended)

## License

This project is open source and available under the MIT License.

## Support

For issues or questions:
1. Check the troubleshooting section
2. Review PHP error logs
3. Verify file and directory permissions
4. Test with a simple file structure first
