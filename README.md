# QuailtyMed - Healthcare Quality Management System

A comprehensive healthcare quality management system designed to comply with **NABH** and **JCI** standards. This system provides checklist management, evidence collection, non-conformance tracking, and compliance reporting for healthcare organizations.

## 🏥 Features

### Core Functionality
- **Department-based Navigation**: Hierarchical navigation through Departments → Asset Types → Document Types → Checklists
- **NABH & JCI Compliance**: Built-in standard mappings with clause references
- **Digital Checklists**: Interactive checklists with pass/fail/N/A options
- **Evidence Management**: Secure file upload and attachment system
- **Non-Conformance Reports (NCR)**: Automatic NCR creation for failed checklist items
- **Comprehensive Reporting**: PDF/CSV export with compliance statistics
- **Audit Trail**: Complete logging of all user actions and changes
- **Role-based Access Control**: 6 user roles with appropriate permissions

### Standards Compliance
- **NABH Standards**: Facility Management & Safety (FMS) clauses mapped to checklist items
- **JCI Standards**: International patient safety and quality standards integration
- **Evidence Requirements**: Mandatory documentation for non-compliant items
- **Corrective Actions**: Built-in CAPA (Corrective and Preventive Actions) workflow

### Mobile-Ready Design
- **Responsive Web Interface**: Mobile-first CSS design
- **PWA Support**: Progressive Web App capabilities
- **Offline Functionality**: Basic offline support with service worker
- **Touch-friendly**: Optimized for tablet and smartphone use

## 🛠️ Technical Stack

- **Frontend**: HTML5, CSS3, Vanilla JavaScript
- **Backend**: PHP 7.4+ with PDO
- **Database**: MySQL 5.7+ / MariaDB
- **Server**: Apache (XAMPP)
- **Security**: Bcrypt password hashing, prepared statements, CSRF protection

## 📋 System Requirements

- **XAMPP**: Version 8.0+ (includes Apache, MySQL, PHP)
- **PHP**: Version 7.4 or higher
- **MySQL**: Version 5.7 or higher
- **Web Browser**: Modern browser with JavaScript enabled
- **Storage**: Minimum 100MB free space

## ⚡ Quick Setup (XAMPP)

### 1. Install XAMPP
Download and install XAMPP from [https://www.apachefriends.org/](https://www.apachefriends.org/)

### 2. Extract Project
Extract the project files to your XAMPP htdocs directory:
```
C:\xampp\htdocs\qms-project\
```

### 3. Start Services
1. Open XAMPP Control Panel
2. Start **Apache** and **MySQL** services
3. Ensure both services show "Running" status

### 4. Create Database
1. Open **phpMyAdmin** at [http://localhost/phpmyadmin](http://localhost/phpmyadmin)
2. Click "Import" tab
3. Choose file: `database.sql` from the project root
4. Click "Go" to import the database

### 5. Configure Database Connection
Edit `config.php` if your database settings differ:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');          // Change if you set a MySQL password
define('DB_NAME', 'quailtymed');
```

### 6. Set Permissions
Ensure the `uploads/` directory is writable:
```bash
chmod 755 uploads/
```

### 7. Access Application
Open your web browser and navigate to:
```
http://localhost/qms-project/
```

## 👤 Default Login Credentials

```
Email: admin@quailtymed.com
Password: admin123
Role: Super Administrator
```

**⚠️ Important**: Change the default password after first login!

## 📂 Project Structure

```
qms-project/
├── api/                    # Backend API endpoints
│   ├── auth.php           # Authentication & session management
│   ├── departments.php    # Department & asset type APIs
│   ├── checklists.php     # Checklist management APIs
│   ├── uploads.php        # File upload handling
│   └── reports.php        # Report generation & export
├── css/
│   └── style.css          # Main stylesheet (mobile-first)
├── js/
│   ├── api.js            # API helper functions
│   └── app.js            # Main application logic
├── uploads/               # File upload directory (secured)
├── assets/               # Static assets (icons, images)
├── config.php            # Database & app configuration
├── database.sql          # Database schema & sample data
├── index.html            # Main application interface
└── README.md            # This file
```

## 🔧 Configuration Options

### Database Settings (`config.php`)
```php
define('DB_HOST', 'localhost');        // Database host
define('DB_USER', 'root');            // Database username
define('DB_PASS', '');                // Database password
define('DB_NAME', 'quailtymed');      // Database name
```

### Application Settings
```php
define('SESSION_TIMEOUT', 3600);      // Session timeout (seconds)
define('MAX_FILE_SIZE', 5242880);     // Max upload size (5MB)
define('APP_URL', 'http://localhost/qms-project/');
```

### Timezone Configuration
```php
date_default_timezone_set('Asia/Kolkata');  // Adjust as needed
```

## 🚀 Core API Endpoints

### Authentication
- `POST /api/auth.php` - User login
- `GET /api/auth.php` - Get current user
- `DELETE /api/auth.php` - Logout

### Departments & Navigation
- `GET /api/departments.php` - List departments
- `GET /api/departments.php?id=1&asset_types=1` - Get asset types
- `GET /api/departments.php?asset_type_id=1&document_types=1` - Get document types

### Checklists
- `GET /api/checklists.php` - List user checklists
- `GET /api/checklists.php?id=1` - Get specific checklist
- `POST /api/checklists.php` - Create new checklist
- `PUT /api/checklists.php?id=1&item_id=1` - Update checklist item

### File Management
- `POST /api/uploads.php` - Upload evidence file
- `GET /api/uploads.php?file=filename` - Download file
- `DELETE /api/uploads.php?id=1` - Delete attachment

### Reports
- `GET /api/reports.php?type=checklists&format=csv` - Export checklist report
- `GET /api/reports.php?type=compliance&format=pdf` - Export compliance report
- `GET /api/reports.php?type=ncrs&format=json` - Get NCR data

## 📊 Sample Data

The system includes sample data for demonstration:

### Departments
- **Maintenance**: Electrical, generators, HVAC systems
- **Radiology**: X-ray, CT, MRI equipment
- **Laboratory**: Analyzers, centrifuges
- **ICU**: Ventilators, patient monitors
- **OT**: Operation theatre equipment
- **Pharmacy**: Storage and dispensing equipment

### Standards Mapping
- **NABH Standards**: FMS.1 through FMS.5 (Facility Management & Safety)
- **JCI Standards**: FMS.01.01.01 through FMS.05.01.01 (Equipment & Utilities)

### User Roles
1. **Super Admin**: Full system access
2. **Admin**: Administrative functions
3. **Auditor**: Audit and compliance functions
4. **Department Manager**: Department-specific management
5. **Technician**: Checklist execution and maintenance
6. **Viewer**: Read-only access

## 🔍 Usage Guide

### Basic Workflow

1. **Login**: Use provided credentials or create new user
2. **Navigate**: Select Department → Asset Type → Document Type
3. **Execute Checklist**: 
   - Mark items as Pass/Fail/N/A
   - Add remarks for failed items
   - Upload evidence files
   - Submit completed checklist
4. **Handle Non-Conformances**: 
   - Review auto-generated NCRs
   - Assign corrective actions
   - Track resolution progress
5. **Generate Reports**: Export compliance data in PDF/CSV format

### Department Cascade Navigation

The system implements a hierarchical navigation pattern:

```
Hospital
└── Departments (e.g., Maintenance)
    └── Asset Types (e.g., Transformer, Generator)
        └── Document Types (e.g., Daily Checklist, PM)
            └── Checklists (Individual inspection records)
```

### Checklist Execution

1. **Standard Reference**: Each item shows NABH/JCI clause mapping
2. **Result Selection**: Choose Pass, Fail, or N/A
3. **Remarks**: Mandatory for failed items
4. **Evidence**: Upload photos, documents, or reports
5. **Auto-NCR**: Failed items automatically generate NCRs

## 📈 Reporting Features

### Available Reports

1. **Checklist Compliance Report**
   - Filter by department, date range
   - Compliance percentage calculations
   - Export as CSV/PDF

2. **Non-Conformance Report**
   - NCR status tracking
   - Severity analysis
   - Overdue CAPA identification

3. **Compliance Summary**
   - Department-wise compliance
   - Standards-wise breakdown
   - Overall compliance metrics

4. **Maintenance Schedule Report**
   - Preventive maintenance tracking
   - Due date monitoring
   - Asset maintenance history

### Export Formats
- **CSV**: Excel-compatible with UTF-8 encoding
- **PDF**: Formatted HTML export (can be converted to PDF)
- **JSON**: Raw data for system integration

## 🔒 Security Features

### Authentication & Authorization
- **Bcrypt Password Hashing**: Industry-standard password security
- **Session Management**: Secure session handling with timeout
- **Role-based Access Control**: Granular permission system
- **CSRF Protection**: Cross-site request forgery prevention

### Data Protection
- **Prepared Statements**: SQL injection prevention
- **Input Sanitization**: XSS attack prevention
- **File Upload Security**: MIME type validation, secure storage
- **Audit Logging**: Complete action tracking

### File Security
- **Upload Directory Protection**: .htaccess file prevents direct access
- **File Type Restrictions**: Only allowed MIME types accepted
- **Size Limitations**: Maximum 5MB per file
- **Secure Naming**: UUID-based filename generation

## 🔧 Troubleshooting

### Common Issues

#### Database Connection Error
```
Solution: Check config.php database settings and ensure MySQL is running
```

#### File Upload Fails
```
Solution: Check uploads/ directory permissions (chmod 755)
```

#### Login Issues
```
Solution: Verify database.sql was imported correctly and contains user data
```

#### Reports Not Generating
```
Solution: Ensure user has appropriate role permissions
```

### Debug Mode
Enable debug mode in `config.php`:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

### Log Files
Check Apache error logs:
- Windows: `C:\xampp\apache\logs\error.log`
- Linux: `/opt/lampp/logs/error.log`

## 📱 Mobile App Development (Optional)

The system is designed as a Progressive Web App (PWA) but can be converted to a native mobile app using **Apache Cordova** or **Capacitor**.

### Capacitor Setup (Quick Guide)

1. **Install Capacitor**:
   ```bash
   npm install @capacitor/core @capacitor/cli
   ```

2. **Initialize Capacitor**:
   ```bash
   npx cap init QuailtyMed com.hospital.quailtymed
   ```

3. **Add Platforms**:
   ```bash
   npx cap add android
   npx cap add ios
   ```

4. **Build and Sync**:
   ```bash
   npx cap sync
   ```

5. **Open in IDE**:
   ```bash
   npx cap open android
   npx cap open ios
   ```

### PWA Installation
Users can install the web app directly from their browser:
1. Visit the application URL
2. Look for "Add to Home Screen" or "Install" prompt
3. Follow browser-specific installation steps

## 🧪 Testing

### Manual Test Cases

#### Login & Authentication
- [ ] Login with valid credentials
- [ ] Login with invalid credentials
- [ ] Session timeout handling
- [ ] Role-based access restrictions

#### Navigation Flow
- [ ] Department selection loads asset types
- [ ] Asset type selection loads document types
- [ ] Document type selection creates checklist
- [ ] Back navigation maintains state

#### Checklist Functionality
- [ ] Items display with NABH/JCI references
- [ ] Pass/Fail/N/A selection works
- [ ] Failed items create NCRs
- [ ] File upload functions correctly
- [ ] Remarks save automatically

#### Reporting
- [ ] Generate CSV report
- [ ] Generate PDF/HTML report
- [ ] Filter by department
- [ ] Filter by date range

### Acceptance Criteria Checklist

✅ **Database & Setup**
- [ ] database.sql imports without errors
- [ ] Application runs on XAMPP with provided instructions
- [ ] Default admin login works

✅ **Core Navigation**
- [ ] Department → Asset Type → Document Type cascade works
- [ ] Checklist items show NABH/JCI standard references
- [ ] Back navigation functions correctly

✅ **Compliance Features**
- [ ] Failed checklist items create NCR entries
- [ ] Standard clause details accessible
- [ ] Evidence upload and attachment works

✅ **Reporting**
- [ ] CSV export functions for sample data
- [ ] PDF/HTML export includes compliance metrics
- [ ] Reports include standard references

✅ **Mobile Support**
- [ ] Responsive design works on mobile devices
- [ ] Touch-friendly interface elements
- [ ] PWA manifest enables mobile installation

## 👥 User Management

### Creating New Users

1. **Super Admin Access**: Login as super admin
2. **Add User**: Navigate to user management (to be implemented in full version)
3. **Assign Role**: Choose appropriate role based on responsibilities
4. **Department Assignment**: Link user to specific department if needed

### Role Permissions Matrix

| Feature | Super Admin | Admin | Auditor | Dept Manager | Technician | Viewer |
|---------|-------------|-------|---------|--------------|------------|--------|
| Create Checklists | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| Update Items | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| Upload Files | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| Generate Reports | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Manage NCRs | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| User Management | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |

## 🤝 Support & Maintenance

### Regular Maintenance Tasks

1. **Database Backup**: Schedule regular MySQL backups
2. **File Cleanup**: Monitor uploads/ directory size
3. **Log Rotation**: Archive old audit logs
4. **Security Updates**: Keep XAMPP/PHP updated
5. **Performance Monitoring**: Check database query performance

### Backup Strategy

```bash
# Database backup
mysqldump -u root -p quailtymed > backup_$(date +%Y%m%d).sql

# Files backup
tar -czf uploads_backup_$(date +%Y%m%d).tar.gz uploads/
```

### Version Updates
1. Backup current system
2. Test updates in development environment
3. Apply database migrations if needed
4. Update application files
5. Verify functionality

## 📄 License

This project is developed for healthcare quality management compliance with NABH and JCI standards. Please ensure appropriate licensing for production use.

## 🌟 Future Enhancements

### Planned Features
- **Advanced Reporting**: More detailed analytics and dashboards
- **Email Notifications**: Automated alerts for due items and NCRs
- **Workflow Management**: Advanced CAPA workflow with approvals
- **Integration APIs**: REST APIs for third-party system integration
- **Advanced User Management**: Complete user and permission management UI
- **Multi-language Support**: Localization for different languages
- **Advanced Scheduling**: Automated maintenance scheduling
- **Digital Signatures**: Electronic signature support for checklists

### Technical Improvements
- **PDF Library Integration**: Professional PDF generation with TCPDF
- **Real-time Updates**: WebSocket support for live updates
- **Advanced Caching**: Redis integration for improved performance
- **API Documentation**: OpenAPI/Swagger documentation
- **Automated Testing**: Unit and integration test suites

---

## 📞 Contact

For technical support or questions about NABH/JCI compliance requirements, please refer to your system administrator or quality management team.

**System Version**: 1.0.0  
**Last Updated**: September 2024  
**Compliance**: NABH 5th Edition, JCI 7th Edition Standards
