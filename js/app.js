/**
 * QuailtyMed Main Application
 * Handles UI interactions, navigation, and business logic
 * Implements department cascade navigation and checklist management
 */

class QuailtyMedApp {
    constructor() {
        this.currentView = 'dashboard';
        this.selectedDepartment = null;
        this.selectedAssetType = null;
        this.selectedDocumentType = null;
        this.currentChecklist = null;
        this.uploadItemId = null;
        
        // Bind methods to maintain context
        this.handleLogin = this.handleLogin.bind(this);
        this.handleLogout = this.handleLogout.bind(this);
        this.loadDepartments = this.loadDepartments.bind(this);
        this.selectDepartment = this.selectDepartment.bind(this);
        this.selectAssetType = this.selectAssetType.bind(this);
        this.selectDocumentType = this.selectDocumentType.bind(this);
    }

    /**
     * Initialize the application
     */
    async init() {
        try {
            console.log('Initializing QuailtyMed...');
            
            // Check if user is already logged in
            const user = await api.getCurrentUser();
            if (user) {
                this.showApp(user);
            } else {
                this.showLogin();
            }
            
            this.bindEvents();
            
        } catch (error) {
            console.error('Failed to initialize app:', error);
            this.showLogin();
        } finally {
            this.hideLoading();
        }
    }

    /**
     * Bind UI event handlers
     */
    bindEvents() {
        // Login form
        const loginForm = document.getElementById('loginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', this.handleLogin);
        }

        // Logout button
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', this.handleLogout);
        }

        // Menu toggle for mobile
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('show');
            });
        }

        // Refresh departments button
        const refreshBtn = document.getElementById('refreshDepartments');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', this.loadDepartments);
        }

        // Back navigation buttons
        document.getElementById('backToDepartments')?.addEventListener('click', () => {
            this.showView('dashboard');
            this.clearDepartmentSelection();
        });

        document.getElementById('backToAssetTypes')?.addEventListener('click', () => {
            this.showAssetTypes(this.selectedDepartment);
        });

        document.getElementById('backToAssets')?.addEventListener('click', () => {
            this.showDocumentTypes(this.selectedAssetType);
        });

        // Modal close handlers
        document.querySelectorAll('.modal-close').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const modal = e.target.closest('.modal');
                if (modal) {
                    this.hideModal(modal);
                }
            });
        });

        // Click outside modal to close
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.hideModal(modal);
                }
            });
        });

        // File upload form
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', this.handleFileUpload.bind(this));
        }

        // Keyboard navigation
        document.addEventListener('keydown', this.handleKeyboard.bind(this));
    }

    /**
     * Handle login form submission
     * @param {Event} e Form submit event
     */
    async handleLogin(e) {
        e.preventDefault();
        
        const form = e.target;
        const email = form.email.value.trim();
        const password = form.password.value;
        const submitBtn = form.querySelector('button[type="submit"]');
        const errorDiv = document.getElementById('loginError');
        
        // Reset error state
        errorDiv.style.display = 'none';
        
        // Show loading state
        submitBtn.querySelector('.btn-text').style.display = 'none';
        submitBtn.querySelector('.btn-loading').style.display = 'inline';
        submitBtn.disabled = true;

        try {
            const response = await api.login(email, password);
            if (response.success) {
                api.showToast('Login successful', 'success');
                this.showApp(response.data);
            } else {
                throw new Error(response.error || 'Login failed');
            }
        } catch (error) {
            console.error('Login error:', error);
            errorDiv.textContent = error.message;
            errorDiv.style.display = 'block';
            api.showToast(error.message, 'error');
        } finally {
            // Reset button state
            submitBtn.querySelector('.btn-text').style.display = 'inline';
            submitBtn.querySelector('.btn-loading').style.display = 'none';
            submitBtn.disabled = false;
        }
    }

    /**
     * Handle logout
     */
    async handleLogout() {
        try {
            await api.logout();
            api.showToast('Logged out successfully', 'info');
            this.showLogin();
        } catch (error) {
            console.error('Logout error:', error);
            // Force logout even if API call fails
            this.showLogin();
        }
    }

    /**
     * Show the login screen
     */
    showLogin() {
        document.getElementById('loginScreen').style.display = 'flex';
        document.getElementById('appContainer').style.display = 'none';
        
        // Focus on email input
        const emailInput = document.getElementById('email');
        if (emailInput) {
            setTimeout(() => emailInput.focus(), 100);
        }
    }

    /**
     * Show the main application
     * @param {Object} user Current user data
     */
    async showApp(user) {
        // Update UI with user info
        document.getElementById('userName').textContent = user.name;
        document.getElementById('userRole').textContent = user.role;
        
        // Show app container
        document.getElementById('loginScreen').style.display = 'none';
        document.getElementById('appContainer').style.display = 'flex';
        
        // Load initial data
        await this.loadDepartments();
        await this.loadDashboardData();
        
        // Show dashboard by default
        this.showView('dashboard');
    }

    /**
     * Hide loading overlay
     */
    hideLoading() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    }

    /**
     * Show specific view
     * @param {string} viewName Name of the view to show
     */
    showView(viewName) {
        // Hide all views
        document.querySelectorAll('.view-container').forEach(view => {
            view.classList.remove('active');
        });
        
        // Show requested view
        const targetView = document.getElementById(viewName + 'View');
        if (targetView) {
            targetView.classList.add('active');
            this.currentView = viewName;
        }
        
        // Close mobile menu
        document.getElementById('sidebar')?.classList.remove('show');
    }

    /**
     * Load departments list
     */
    async loadDepartments() {
        try {
            const departments = await api.getDepartments();
            this.renderDepartments(departments);
        } catch (error) {
            console.error('Failed to load departments:', error);
            api.showToast('Failed to load departments', 'error');
        }
    }

    /**
     * Render departments in sidebar
     * @param {Array} departments List of departments
     */
    renderDepartments(departments) {
        const container = document.getElementById('departmentsList');
        if (!container) return;
        
        container.innerHTML = '';
        
        departments.forEach(dept => {
            const item = document.createElement('div');
            item.className = 'department-item';
            item.setAttribute('data-department-id', dept.id);
            item.innerHTML = `
                <div class="item-name">${api.sanitizeHTML(dept.name)}</div>
                <div class="item-description">${api.sanitizeHTML(dept.description || '')}</div>
            `;
            
            item.addEventListener('click', () => this.selectDepartment(dept));
            container.appendChild(item);
        });
    }

    /**
     * Handle department selection
     * @param {Object} department Selected department
     */
    async selectDepartment(department) {
        this.selectedDepartment = department;
        this.selectedAssetType = null;
        this.selectedDocumentType = null;
        
        // Update UI selection state
        this.updateDepartmentSelection(department.id);
        
        // Load and show asset types
        await this.showAssetTypes(department);
    }

    /**
     * Update department selection UI
     * @param {number} departmentId Selected department ID
     */
    updateDepartmentSelection(departmentId) {
        document.querySelectorAll('.department-item').forEach(item => {
            item.classList.remove('active');
        });
        
        const selectedItem = document.querySelector(`[data-department-id="${departmentId}"]`);
        if (selectedItem) {
            selectedItem.classList.add('active');
        }
    }

    /**
     * Show asset types for department
     * @param {Object} department Department object
     */
    async showAssetTypes(department) {
        try {
            const assetTypes = await api.getAssetTypes(department.id);
            
            document.getElementById('assetTypesTitle').textContent = 
                `Asset Types - ${department.name}`;
            
            this.renderAssetTypes(assetTypes);
            this.showView('assetTypes');
            
        } catch (error) {
            console.error('Failed to load asset types:', error);
            api.showToast('Failed to load asset types', 'error');
        }
    }

    /**
     * Render asset types list
     * @param {Array} assetTypes List of asset types
     */
    renderAssetTypes(assetTypes) {
        const container = document.getElementById('assetTypesList');
        if (!container) return;
        
        container.innerHTML = '';
        
        if (assetTypes.length === 0) {
            container.innerHTML = '<p>No asset types found for this department.</p>';
            return;
        }
        
        assetTypes.forEach(assetType => {
            const item = document.createElement('div');
            item.className = 'asset-type-item';
            item.innerHTML = `
                <div class="item-name">${api.sanitizeHTML(assetType.name)}</div>
                <div class="item-description">${api.sanitizeHTML(assetType.description || '')}</div>
            `;
            
            item.addEventListener('click', () => this.selectAssetType(assetType));
            container.appendChild(item);
        });
    }

    /**
     * Handle asset type selection
     * @param {Object} assetType Selected asset type
     */
    async selectAssetType(assetType) {
        this.selectedAssetType = assetType;
        this.selectedDocumentType = null;
        
        await this.showDocumentTypes(assetType);
    }

    /**
     * Show document types for asset type
     * @param {Object} assetType Asset type object
     */
    async showDocumentTypes(assetType) {
        try {
            const documentTypes = await api.getDocumentTypes(assetType.id);
            
            document.getElementById('documentTypesTitle').textContent = 
                `Document Types - ${assetType.name}`;
            
            this.renderDocumentTypes(documentTypes);
            this.showView('documentTypes');
            
        } catch (error) {
            console.error('Failed to load document types:', error);
            api.showToast('Failed to load document types', 'error');
        }
    }

    /**
     * Render document types list
     * @param {Array} documentTypes List of document types
     */
    renderDocumentTypes(documentTypes) {
        const container = document.getElementById('documentTypesList');
        if (!container) return;
        
        container.innerHTML = '';
        
        documentTypes.forEach(docType => {
            const item = document.createElement('div');
            item.className = 'document-type-item';
            item.innerHTML = `
                <div class="item-name">${api.sanitizeHTML(docType.name)}</div>
                <div class="item-description">${api.sanitizeHTML(docType.description || '')}</div>
            `;
            
            item.addEventListener('click', () => this.selectDocumentType(docType));
            container.appendChild(item);
        });
    }

    /**
     * Handle document type selection
     * @param {Object} documentType Selected document type
     */
    async selectDocumentType(documentType) {
        this.selectedDocumentType = documentType;
        
        // For now, create a new checklist automatically
        // In a full implementation, you'd show existing checklists first
        await this.createNewChecklist();
    }

    /**
     * Create a new checklist
     */
    async createNewChecklist() {
        try {
            // For demo purposes, we'll use the first asset from the asset type
            // In a real implementation, you'd have asset selection UI
            const checklistData = {
                asset_id: 1, // Demo asset ID
                document_type_id: this.selectedDocumentType.id,
                checklist_name: `${this.selectedDocumentType.name} - ${new Date().toLocaleDateString()}`
            };
            
            const response = await api.createChecklist(checklistData);
            if (response.success) {
                api.showToast('Checklist created successfully', 'success');
                await this.loadChecklist(response.data.checklist_id);
            }
            
        } catch (error) {
            console.error('Failed to create checklist:', error);
            api.showToast('Failed to create checklist', 'error');
        }
    }

    /**
     * Load and display a checklist
     * @param {number} checklistId Checklist ID
     */
    async loadChecklist(checklistId) {
        try {
            const checklist = await api.getChecklistById(checklistId);
            this.currentChecklist = checklist;
            
            // Update checklist header
            document.getElementById('checklistTitle').textContent = 
                checklist.checklist_name || checklist.document_type_name;
            document.getElementById('checklistInfo').textContent = 
                `Asset: ${checklist.asset_name} (${checklist.asset_tag}) | Location: ${checklist.location}`;
            
            this.renderChecklistItems(checklist.items);
            this.showView('checklist');
            
        } catch (error) {
            console.error('Failed to load checklist:', error);
            api.showToast('Failed to load checklist', 'error');
        }
    }

    /**
     * Render checklist items
     * @param {Array} items Checklist items
     */
    renderChecklistItems(items) {
        const container = document.getElementById('checklistContainer');
        if (!container) return;
        
        container.innerHTML = '';
        
        items.forEach(item => {
            const itemDiv = document.createElement('div');
            itemDiv.className = 'checklist-item';
            itemDiv.innerHTML = this.createChecklistItemHTML(item);
            container.appendChild(itemDiv);
            
            // Bind events for this item
            this.bindChecklistItemEvents(itemDiv, item);
        });
    }

    /**
     * Create HTML for checklist item
     * @param {Object} item Checklist item data
     * @returns {string} HTML string
     */
    createChecklistItemHTML(item) {
        const standardBadge = item.standard_id ? `
            <div class="standard-reference">
                <span class="standard-badge" data-standard-id="${item.standard_id}" 
                      title="Click to view standard details">
                    ${item.standard_source} ${item.clause_id}
                </span>
                <span>${api.sanitizeHTML(item.standard_title || '')}</span>
            </div>
        ` : '';

        const resultClass = item.result || 'pending';
        const remarksValue = api.sanitizeHTML(item.remarks || '');
        const attachmentInfo = item.attached_file ? `
            <div class="attachment-info">
                📎 Evidence attached
            </div>
        ` : '';

        return `
            <div class="checklist-question">
                ${api.sanitizeHTML(item.question)}
            </div>
            
            ${standardBadge}
            
            <div class="checklist-controls">
                <div class="result-options">
                    <button class="result-option pass ${resultClass === 'pass' ? 'active' : ''}" 
                            data-result="pass">✓ Pass</button>
                    <button class="result-option fail ${resultClass === 'fail' ? 'active' : ''}" 
                            data-result="fail">✗ Fail</button>
                    <button class="result-option na ${resultClass === 'na' ? 'active' : ''}" 
                            data-result="na">N/A</button>
                </div>
            </div>
            
            <div class="remarks-section">
                <textarea class="remarks-input" placeholder="Add remarks..." 
                          data-item-id="${item.id}">${remarksValue}</textarea>
            </div>
            
            <div class="upload-evidence">
                <button class="upload-btn" data-item-id="${item.id}">
                    📎 Upload Evidence
                </button>
                ${attachmentInfo}
            </div>
        `;
    }

    /**
     * Bind events for checklist item
     * @param {Element} itemDiv Item container element
     * @param {Object} item Item data
     */
    bindChecklistItemEvents(itemDiv, item) {
        // Result option buttons
        itemDiv.querySelectorAll('.result-option').forEach(btn => {
            btn.addEventListener('click', () => {
                this.handleResultSelection(itemDiv, item.id, btn.dataset.result);
            });
        });

        // Standard badge click
        const standardBadge = itemDiv.querySelector('.standard-badge');
        if (standardBadge) {
            standardBadge.addEventListener('click', () => {
                this.showStandardModal(item.standard_id);
            });
        }

        // Remarks input (debounced save)
        const remarksInput = itemDiv.querySelector('.remarks-input');
        if (remarksInput) {
            const debouncedSave = api.debounce(() => {
                this.saveChecklistItem(item.id);
            }, 1000);
            
            remarksInput.addEventListener('input', debouncedSave);
        }

        // Upload button
        const uploadBtn = itemDiv.querySelector('.upload-btn');
        if (uploadBtn) {
            uploadBtn.addEventListener('click', () => {
                this.showUploadModal(item.id);
            });
        }
    }

    /**
     * Handle result selection for checklist item
     * @param {Element} itemDiv Item container element
     * @param {number} itemId Item ID
     * @param {string} result Selected result
     */
    handleResultSelection(itemDiv, itemId, result) {
        // Update UI state
        itemDiv.querySelectorAll('.result-option').forEach(btn => {
            btn.classList.remove('active');
        });
        itemDiv.querySelector(`[data-result="${result}"]`).classList.add('active');
        
        // Save the change
        this.saveChecklistItem(itemId, result);
    }

    /**
     * Save checklist item changes
     * @param {number} itemId Item ID
     * @param {string} result Optional result value
     */
    async saveChecklistItem(itemId, result = null) {
        try {
            const itemDiv = document.querySelector(`[data-item-id="${itemId}"]`).closest('.checklist-item');
            const remarksInput = itemDiv.querySelector('.remarks-input');
            const activeResult = itemDiv.querySelector('.result-option.active');
            
            const updateData = {
                result: result || (activeResult ? activeResult.dataset.result : 'pending'),
                remarks: remarksInput ? remarksInput.value : ''
            };
            
            const response = await api.updateChecklistItem(
                this.currentChecklist.id, 
                itemId, 
                updateData
            );
            
            if (response.success) {
                // Show success feedback
                api.showToast('Item updated', 'success', 2000);
                
                // If item failed, show NCR created message
                if (updateData.result === 'fail') {
                    api.showToast('Non-conformance report (NCR) created', 'warning');
                }
            }
            
        } catch (error) {
            console.error('Failed to save checklist item:', error);
            api.showToast('Failed to save changes', 'error');
        }
    }

    /**
     * Show standard details modal
     * @param {number} standardId Standard ID
     */
    async showStandardModal(standardId) {
        // For now, show a simple modal with standard info
        // In a full implementation, you'd fetch standard details from API
        const modal = document.getElementById('standardModal');
        document.getElementById('standardModalTitle').textContent = 'Standard Details';
        document.getElementById('standardModalContent').innerHTML = `
            <p>Standard ID: ${standardId}</p>
            <p>This would show full standard clause details from NABH/JCI database.</p>
            <p>Implementation note: Add API endpoint to fetch standard details.</p>
        `;
        
        this.showModal(modal);
    }

    /**
     * Show file upload modal
     * @param {number} itemId Checklist item ID
     */
    showUploadModal(itemId) {
        this.uploadItemId = itemId;
        const modal = document.getElementById('uploadModal');
        this.showModal(modal);
    }

    /**
     * Handle file upload
     * @param {Event} e Form submit event
     */
    async handleFileUpload(e) {
        e.preventDefault();
        
        const form = e.target;
        const fileInput = form.querySelector('#fileInput');
        const file = fileInput.files[0];
        
        if (!file) {
            api.showToast('Please select a file', 'error');
            return;
        }
        
        // Validate file
        const validation = api.validateFile(file);
        if (!validation.valid) {
            api.showToast(validation.error, 'error');
            return;
        }
        
        try {
            const response = await api.uploadFile(file, 'checklist_item', this.uploadItemId);
            
            if (response.success) {
                api.showToast('File uploaded successfully', 'success');
                this.hideModal(document.getElementById('uploadModal'));
                
                // Refresh checklist to show attachment
                await this.loadChecklist(this.currentChecklist.id);
            }
            
        } catch (error) {
            console.error('File upload failed:', error);
            api.showToast('File upload failed', 'error');
        }
    }

    /**
     * Load dashboard data
     */
    async loadDashboardData() {
        try {
            // For now, show static data
            // In a full implementation, you'd have dashboard API endpoints
            document.getElementById('pendingChecklists').textContent = '12';
            document.getElementById('overduePMs').textContent = '3';
            document.getElementById('openNCRs').textContent = '5';
            document.getElementById('complianceRate').textContent = '94%';
            
        } catch (error) {
            console.error('Failed to load dashboard data:', error);
        }
    }

    /**
     * Clear department selection
     */
    clearDepartmentSelection() {
        this.selectedDepartment = null;
        this.selectedAssetType = null;
        this.selectedDocumentType = null;
        
        document.querySelectorAll('.department-item').forEach(item => {
            item.classList.remove('active');
        });
    }

    /**
     * Show modal
     * @param {Element} modal Modal element
     */
    showModal(modal) {
        modal.classList.add('show');
        modal.style.display = 'flex';
        
        // Focus first input in modal
        const firstInput = modal.querySelector('input, textarea, select, button');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }
    }

    /**
     * Hide modal
     * @param {Element} modal Modal element
     */
    hideModal(modal) {
        modal.classList.remove('show');
        setTimeout(() => {
            modal.style.display = 'none';
        }, 300);
    }

    /**
     * Handle keyboard shortcuts
     * @param {KeyboardEvent} e Keyboard event
     */
    handleKeyboard(e) {
        // ESC key closes modals
        if (e.key === 'Escape') {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                this.hideModal(openModal);
            }
        }
        
        // Alt+D for dashboard
        if (e.altKey && e.key === 'd') {
            e.preventDefault();
            this.showView('dashboard');
        }
    }
}

// Initialize app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const app = new QuailtyMedApp();
    window.app = app; // Make globally accessible for debugging
    app.init();
});

// Handle browser back/forward buttons
window.addEventListener('popstate', () => {
    // Handle navigation state if needed
});

// Handle app visibility changes (for PWA)
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        // App became visible - could refresh data
        console.log('App is visible');
    }
});

// Service worker registration for offline support
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js')
        .then(registration => {
            console.log('SW registered: ', registration);
        })
        .catch(registrationError => {
            console.log('SW registration failed: ', registrationError);
        });
}
