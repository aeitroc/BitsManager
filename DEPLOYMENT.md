# 🚀 Deployment Setup Guide

This guide will help you set up automatic deployment of your BitsManager file browser to your server using GitHub Actions.

## 📋 Prerequisites

- A server with SSH access (VPS, dedicated server, shared hosting with SSH)
- Git installed on your server
- Web server (Apache/Nginx) running PHP 7.4+
- GitHub repository with the BitsManager code

## 🔑 Step 1: Generate SSH Key Pair

On your **local machine**, generate an SSH key pair for GitHub Actions:

```bash
# Generate a new SSH key pair
ssh-keygen -t rsa -b 4096 -f ~/.ssh/github_actions_key

# This creates two files:
# ~/.ssh/github_actions_key (private key - keep secret!)
# ~/.ssh/github_actions_key.pub (public key - add to server)
```

## 🖥️ Step 2: Configure Your Server

### 2.1 Add Public Key to Server

Copy the public key to your server:

```bash
# Copy public key to server
ssh-copy-id -i ~/.ssh/github_actions_key.pub user@your-server.com

# Or manually add it to authorized_keys:
cat ~/.ssh/github_actions_key.pub | ssh user@your-server.com "mkdir -p ~/.ssh && cat >> ~/.ssh/authorized_keys"
```

### 2.2 Test SSH Connection

```bash
# Test the connection
ssh -i ~/.ssh/github_actions_key user@your-server.com
```

### 2.3 Prepare Server Directory

On your **server**, prepare the deployment directory:

```bash
# Create web directory (if not exists)
sudo mkdir -p /var/www/html

# Set permissions
sudo chown -R $USER:www-data /var/www/html
sudo chmod -R 755 /var/www/html

# Install git (if not installed)
sudo apt update && sudo apt install git -y  # Ubuntu/Debian
# sudo yum install git -y                   # CentOS/RHEL
```

## ⚙️ Step 3: Configure GitHub Secrets

In your GitHub repository, go to **Settings** → **Secrets and variables** → **Actions** and add these secrets:

### Required Secrets:

| Secret Name | Description | Example Value |
|-------------|-------------|---------------|
| `HOST` | Your server's IP address or domain | `192.168.1.100` or `example.com` |
| `USERNAME` | SSH username on your server | `ubuntu`, `root`, `your-user` |
| `PRIVATE_KEY` | The **entire** private key content | Copy from `~/.ssh/github_actions_key` |

### Optional Secrets:

| Secret Name | Description | Default Value |
|-------------|-------------|---------------|
| `PORT` | SSH port (if not 22) | `22` |
| `DEPLOY_PATH` | Deployment directory on server | `/var/www/html` |
| `HEALTH_CHECK_URL` | URL to check after deployment | `https://yoursite.com/filemanager/` |

### 🔐 How to Add Private Key:

1. On your local machine, copy the private key:
   ```bash
   cat ~/.ssh/github_actions_key
   ```

2. Copy the **entire output** including the header and footer:
   ```
   -----BEGIN OPENSSH PRIVATE KEY-----
   [key content here]
   -----END OPENSSH PRIVATE KEY-----
   ```

3. Paste it into the `PRIVATE_KEY` secret in GitHub

## 🔧 Step 4: Customize Deployment (Optional)

### 4.1 Apache Configuration

If using Apache, create a virtual host:

```apache
# /etc/apache2/sites-available/filemanager.conf
<VirtualHost *:80>
    ServerName filemanager.yoursite.com
    DocumentRoot /var/www/html/filemanager
    
    <Directory "/var/www/html/filemanager">
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/filemanager_error.log
    CustomLog ${APACHE_LOG_DIR}/filemanager_access.log combined
</VirtualHost>
```

Enable the site:
```bash
sudo a2ensite filemanager
sudo systemctl reload apache2
```

### 4.2 Nginx Configuration

If using Nginx:

```nginx
# /etc/nginx/sites-available/filemanager
server {
    listen 80;
    server_name filemanager.yoursite.com;
    root /var/www/html/filemanager;
    index index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

Enable the site:
```bash
sudo ln -s /etc/nginx/sites-available/filemanager /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## 🚦 Step 5: Test Deployment

### 5.1 Trigger First Deployment

1. Make a small change to your code
2. Commit and push to the `main` branch:
   ```bash
   git add .
   git commit -m "Initial deployment setup"
   git push origin main
   ```

3. Go to your GitHub repository → **Actions** tab
4. Watch the deployment workflow run

### 5.2 Monitor Deployment

The workflow will:
- ✅ Validate PHP syntax
- ✅ Run security checks
- ✅ Deploy to your server
- ✅ Set proper permissions
- ✅ Create backups
- ✅ Perform health checks

## 🔍 Troubleshooting

### Common Issues:

#### 1. SSH Connection Failed
```bash
# Test SSH manually
ssh -i ~/.ssh/github_actions_key user@your-server.com

# Check if key was added correctly
cat ~/.ssh/authorized_keys  # on server
```

#### 2. Permission Denied
```bash
# Fix web directory permissions on server
sudo chown -R www-data:www-data /var/www/html/filemanager
sudo chmod -R 755 /var/www/html/filemanager
```

#### 3. PHP Syntax Errors
- Check the Actions log for specific PHP syntax errors
- Fix the errors and push again

#### 4. Health Check Failed
- Verify your `HEALTH_CHECK_URL` is correct
- Check web server error logs
- Ensure the site is accessible

### 📊 Monitoring Deployments

#### View Deployment Status:
- GitHub repository → **Actions** tab
- Check recent workflow runs
- View detailed logs for each step

#### Manual Rollback:
1. Go to **Actions** tab
2. Click **Run workflow**
3. Select the **rollback** workflow
4. Click **Run workflow**

## 🛡️ Security Best Practices

1. **Use dedicated SSH key**: Don't reuse your personal SSH key
2. **Limit SSH key permissions**: Consider using a dedicated deployment user
3. **Regular key rotation**: Rotate SSH keys periodically
4. **Monitor deployments**: Check deployment logs regularly
5. **Backup strategy**: The workflow creates automatic backups

## 🔄 Workflow Features

### Automatic Deployment:
- ✅ Triggers on push to `main` branch
- ✅ Runs PHP syntax validation
- ✅ Performs security checks
- ✅ Creates automatic backups
- ✅ Sets proper permissions
- ✅ Health check after deployment

### Manual Rollback:
- ✅ Can be triggered manually
- ✅ Restores from latest backup
- ✅ Maintains file permissions

### Security Checks:
- ✅ Prevents `eval()` usage
- ✅ Blocks remote file inclusion
- ✅ Detects shell execution functions
- ✅ Validates PHP syntax

## 📞 Support

If you encounter issues:

1. Check the **Actions** tab for detailed error logs
2. Verify all secrets are correctly configured
3. Test SSH connection manually
4. Check server permissions and web server status

Happy deploying! 🚀
