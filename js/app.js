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
        this.currentUser = null;
        this.currentUserId = null; // For user management
        this.usersData = [];
        this.currentPage = 1;
        this.usersPerPage = 20;
        this.currentAssetId = null; // For asset management
        this.assetsData = [];
        this.assetCurrentPage = 1;
        this.assetsPerPage = 20;
        this.currentNCRId = null; // For NCR management
        this.ncrsData = [];
        this.ncrCurrentPage = 1;
        this.ncrsPerPage = 20;
        
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

        document.getElementById('backToDocumentTypes')?.addEventListener('click', () => {
            this.showDocumentTypes(this.selectedAssetType);
        });

        document.getElementById('backToAssetSelection')?.addEventListener('click', () => {
            this.showAssetSelection();
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

        // User management events
        document.getElementById('userManagementMenuItem')?.addEventListener('click', () => {
            this.showUserManagement();
        });
        
        document.getElementById('assetManagementMenuItem')?.addEventListener('click', () => {
            this.showAssetManagement();
        });
        
        document.getElementById('ncrManagementMenuItem')?.addEventListener('click', () => {
            this.showNCRManagement();
        });
        
        document.getElementById('reportsMenuItem')?.addEventListener('click', () => {
            this.showReports();
        });
        
        document.getElementById('addUserBtn')?.addEventListener('click', () => {
            this.showUserModal();
        });
        
        document.getElementById('addAssetBtn')?.addEventListener('click', () => {
            this.showAssetModal();
        });
        
        document.getElementById('addNCRBtn')?.addEventListener('click', () => {
            this.showNCRModal();
        });
        
        // User management filters
        document.getElementById('userSearch')?.addEventListener('input', 
            api.debounce(() => this.filterUsers(), 500)
        );
        
        document.getElementById('roleFilter')?.addEventListener('change', () => {
            this.filterUsers();
        });
        
        document.getElementById('statusFilter')?.addEventListener('change', () => {
            this.filterUsers();
        });
        
        // Asset management filters
        document.getElementById('assetSearch')?.addEventListener('input', 
            api.debounce(() => this.filterAssets(), 500)
        );
        
        document.getElementById('assetTypeFilter')?.addEventListener('change', () => {
            this.filterAssets();
        });
        
        document.getElementById('assetStatusFilter')?.addEventListener('change', () => {
            this.filterAssets();
        });
        
        document.getElementById('assetDepartmentFilter')?.addEventListener('change', () => {
            this.filterAssets();
        });
        
        // NCR management filters
        document.getElementById('ncrSearch')?.addEventListener('input', 
            api.debounce(() => this.filterNCRs(), 500)
        );
        
        document.getElementById('ncrStatusFilter')?.addEventListener('change', () => {
            this.filterNCRs();
        });
        
        document.getElementById('ncrSeverityFilter')?.addEventListener('change', () => {
            this.filterNCRs();
        });
        
        document.getElementById('ncrDepartmentFilter')?.addEventListener('change', () => {
            this.filterNCRs();
        });
        
        // Form submissions
        document.getElementById('userForm')?.addEventListener('submit', this.handleUserFormSubmit.bind(this));
        document.getElementById('passwordResetForm')?.addEventListener('submit', this.handlePasswordReset.bind(this));
        document.getElementById('assetForm')?.addEventListener('submit', this.handleAssetFormSubmit.bind(this));
        document.getElementById('ncrForm')?.addEventListener('submit', this.handleNCRFormSubmit.bind(this));

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
        this.currentUser = user;
        
        // Update UI with user info
        document.getElementById('userName').textContent = user.name;
        document.getElementById('userRole').textContent = user.role;
        
        // Show/hide admin menu based on role
        const adminMenu = document.getElementById('adminMenu');
        if (adminMenu) {
            if (user.role === 'superadmin' || user.role === 'admin') {
                adminMenu.style.display = 'block';
            } else {
                adminMenu.style.display = 'none';
            }
        }
        
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
        
        // Show asset selection before creating checklist
        await this.showAssetSelection();
    }

    /**
     * Show asset selection for checklist creation
     */
    async showAssetSelection() {
        try {
            // Get available assets for the selected asset type
            const response = await api.request('POST', 'checklists.php', {
                get_assets: true,
                asset_type_id: this.selectedAssetType.id,
                document_type_id: this.selectedDocumentType.id
            });
            
            if (response.success) {
                this.renderAssetSelection(response.data);
                this.showView('assetSelection');
            } else {
                throw new Error(response.error);
            }
            
        } catch (error) {
            console.error('Failed to load assets:', error);
            api.showToast('Failed to load assets for selection', 'error');
        }
    }

    /**
     * Render asset selection interface
     * @param {Array} assets Available assets
     */
    renderAssetSelection(assets) {
        const container = document.getElementById('assetSelectionList');
        if (!container) return;
        
        // Update title
        document.getElementById('assetSelectionTitle').textContent = 
            `Select Asset - ${this.selectedDocumentType.name}`;
        
        container.innerHTML = '';
        
        if (assets.length === 0) {
            container.innerHTML = '<p>No active assets found for this asset type.</p>';
            return;
        }
        
        assets.forEach(asset => {
            const item = document.createElement('div');
            item.className = 'asset-selection-item';
            item.innerHTML = `
                <div class="asset-info">
                    <div class="asset-name">${api.sanitizeHTML(asset.name)}</div>
                    <div class="asset-details">
                        <span class="asset-tag">${api.sanitizeHTML(asset.asset_tag || 'No Tag')}</span>
                        ${asset.location ? `<span class="asset-location">📍 ${api.sanitizeHTML(asset.location)}</span>` : ''}
                        ${asset.model ? `<span class="asset-model">🔧 ${api.sanitizeHTML(asset.model)}</span>` : ''}
                    </div>
                    <div class="asset-department">${api.sanitizeHTML(asset.department_name)} > ${api.sanitizeHTML(asset.asset_type_name)}</div>
                </div>
                <button class="select-asset-btn" data-asset-id="${asset.id}">
                    Select Asset
                </button>
            `;
            
            const selectBtn = item.querySelector('.select-asset-btn');
            selectBtn.addEventListener('click', () => {
                this.createNewChecklistWithAsset(asset);
            });
            
            container.appendChild(item);
        });
    }

    /**
     * Create a new checklist with selected asset
     * @param {Object} selectedAsset Selected asset object
     */
    async createNewChecklistWithAsset(selectedAsset) {
        try {
            const checklistData = {
                asset_id: selectedAsset.id,
                document_type_id: this.selectedDocumentType.id,
                checklist_name: `${this.selectedDocumentType.name} - ${selectedAsset.name} - ${new Date().toLocaleDateString()}`
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
            // Load real dashboard metrics from the maintenance API
            const response = await api.request('GET', 'maintenance.php?dashboard=true');
            
            if (response.success) {
                const metrics = response.data;
                
                // Update dashboard cards with real data
                document.getElementById('pendingChecklists').textContent = metrics.pending_checklists || '0';
                document.getElementById('overduePMs').textContent = metrics.overdue_maintenance || '0';
                document.getElementById('openNCRs').textContent = metrics.open_ncrs || '0';
                document.getElementById('complianceRate').textContent = `${metrics.compliance_rate || 0}%`;
                
                // Update card colors based on values
                this.updateDashboardCardColors(metrics);
                
                // Load department filter options
                await this.loadDashboardFilters();
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load dashboard data:', error);
            
            // Fallback to static data if API fails
            document.getElementById('pendingChecklists').textContent = '-';
            document.getElementById('overduePMs').textContent = '-';
            document.getElementById('openNCRs').textContent = '-';
            document.getElementById('complianceRate').textContent = '-%';
            
            api.showToast('Failed to load dashboard metrics', 'error');
        }
    }

    /**
     * Update dashboard card colors based on metric values
     * @param {Object} metrics Dashboard metrics
     */
    updateDashboardCardColors(metrics) {
        // Update overdue PMs card color
        const overduePMsCard = document.getElementById('overduePMs').closest('.dashboard-card');
        if (overduePMsCard) {
            if (metrics.overdue_maintenance > 0) {
                overduePMsCard.classList.add('urgent');
            } else {
                overduePMsCard.classList.remove('urgent');
            }
        }
        
        // Update open NCRs card color
        const openNCRsCard = document.getElementById('openNCRs').closest('.dashboard-card');
        if (openNCRsCard) {
            if (metrics.open_ncrs > 5) {
                openNCRsCard.classList.add('warning');
            } else {
                openNCRsCard.classList.remove('warning');
            }
        }
        
        // Update compliance rate card color
        const complianceCard = document.getElementById('complianceRate').closest('.dashboard-card');
        if (complianceCard) {
            if (metrics.compliance_rate < 85) {
                complianceCard.classList.add('warning');
            } else if (metrics.compliance_rate >= 95) {
                complianceCard.classList.add('success');
            } else {
                complianceCard.classList.remove('warning', 'success');
            }
        }
    }

    /**
     * Load dashboard filter options
     */
    async loadDashboardFilters() {
        try {
            const departments = await api.getDepartments();
            const filterSelect = document.getElementById('dashboardFilter');
            
            if (filterSelect) {
                // Clear existing options except the first one
                while (filterSelect.children.length > 1) {
                    filterSelect.removeChild(filterSelect.lastChild);
                }
                
                // Add department options
                departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.id;
                    option.textContent = dept.name;
                    filterSelect.appendChild(option);
                });
                
                // Add filter change event
                filterSelect.addEventListener('change', () => {
                    this.loadDashboardData();
                });
            }
        } catch (error) {
            console.error('Failed to load dashboard filters:', error);
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

    // =============================================
    // USER MANAGEMENT METHODS
    // =============================================

    /**
     * Show user management interface
     */
    async showUserManagement() {
        if (!this.hasAdminPermission()) {
            api.showToast('Access denied: Insufficient permissions', 'error');
            return;
        }
        
        this.showView('userManagement');
        await this.loadUsers();
        await this.loadDepartmentsForUserForm();
    }

    /**
     * Check if current user has admin permissions
     * @returns {boolean}
     */
    hasAdminPermission() {
        return this.currentUser && (this.currentUser.role === 'superadmin' || this.currentUser.role === 'admin');
    }

    /**
     * Load users list with filters
     */
    async loadUsers() {
        try {
            const filters = this.getUserFilters();
            const response = await api.request('GET', 'users.php', filters);
            
            if (response.success) {
                this.usersData = response.data.users;
                this.renderUsersTable(response.data.users);
                this.renderUsersPagination(response.data.pagination);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load users:', error);
            api.showToast('Failed to load users', 'error');
        }
    }

    /**
     * Get current filter values
     * @returns {Object} Filter parameters
     */
    getUserFilters() {
        return {
            page: this.currentPage,
            limit: this.usersPerPage,
            search: document.getElementById('userSearch')?.value || '',
            role: document.getElementById('roleFilter')?.value || '',
            status: document.getElementById('statusFilter')?.value || ''
        };
    }

    /**
     * Filter users based on current criteria
     */
    async filterUsers() {
        this.currentPage = 1;
        await this.loadUsers();
    }

    /**
     * Render users table
     * @param {Array} users List of users
     */
    renderUsersTable(users) {
        const tbody = document.getElementById('usersTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        users.forEach(user => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <div class="user-info">
                        <div class="user-name">${api.sanitizeHTML(user.name)}</div>
                        <div class="user-id">#${user.id}</div>
                    </div>
                </td>
                <td>${api.sanitizeHTML(user.email)}</td>
                <td>
                    <span class="role-badge role-${user.role}">${this.formatRole(user.role)}</span>
                </td>
                <td>${api.sanitizeHTML(user.department_name || 'N/A')}</td>
                <td>
                    <span class="status-badge ${user.is_active ? 'active' : 'inactive'}">
                        ${user.is_active ? 'Active' : 'Inactive'}
                    </span>
                </td>
                <td>${new Date(user.created_at).toLocaleDateString()}</td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-icon" onclick="app.editUser(${user.id})" title="Edit User">
                            ✏️
                        </button>
                        <button class="btn-icon" onclick="app.resetUserPassword(${user.id})" title="Reset Password">
                            🔑
                        </button>
                        ${user.is_active ? 
                            `<button class="btn-icon danger" onclick="app.deactivateUser(${user.id})" title="Deactivate User">🚫</button>` :
                            `<button class="btn-icon success" onclick="app.activateUser(${user.id})" title="Activate User">✅</button>`
                        }
                    </div>
                </td>
            `;
            tbody.appendChild(row);
        });
    }

    /**
     * Format role for display
     * @param {string} role User role
     * @returns {string} Formatted role
     */
    formatRole(role) {
        const roleMap = {
            'superadmin': 'Super Admin',
            'admin': 'Admin',
            'auditor': 'Auditor',
            'dept_manager': 'Dept Manager',
            'technician': 'Technician',
            'viewer': 'Viewer'
        };
        return roleMap[role] || role;
    }

    /**
     * Render pagination for users
     * @param {Object} pagination Pagination data
     */
    renderUsersPagination(pagination) {
        const container = document.getElementById('usersPagination');
        if (!container) return;
        
        let html = '<div class="pagination-info">';
        html += `Showing ${((pagination.page - 1) * pagination.limit) + 1}-${Math.min(pagination.page * pagination.limit, pagination.total)} of ${pagination.total} users`;
        html += '</div>';
        
        if (pagination.pages > 1) {
            html += '<div class="pagination-controls">';
            
            // Previous button
            if (pagination.page > 1) {
                html += `<button class="btn btn-secondary" onclick="app.goToUserPage(${pagination.page - 1})">Previous</button>`;
            }
            
            // Page numbers
            const startPage = Math.max(1, pagination.page - 2);
            const endPage = Math.min(pagination.pages, pagination.page + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === pagination.page ? 'active' : '';
                html += `<button class="btn btn-secondary ${activeClass}" onclick="app.goToUserPage(${i})">${i}</button>`;
            }
            
            // Next button
            if (pagination.page < pagination.pages) {
                html += `<button class="btn btn-secondary" onclick="app.goToUserPage(${pagination.page + 1})">Next</button>`;
            }
            
            html += '</div>';
        }
        
        container.innerHTML = html;
    }

    /**
     * Go to specific page
     * @param {number} page Page number
     */
    async goToUserPage(page) {
        this.currentPage = page;
        await this.loadUsers();
    }

    /**
     * Show user modal for adding/editing
     * @param {Object} user User data for editing (null for new user)
     */
    showUserModal(user = null) {
        const modal = document.getElementById('userModal');
        const form = document.getElementById('userForm');
        const title = document.getElementById('userModalTitle');
        const passwordGroup = document.getElementById('passwordGroup');
        const statusGroup = document.getElementById('statusGroup');
        
        // Reset form
        form.reset();
        
        if (user) {
            // Edit mode
            title.textContent = 'Edit User';
            this.currentUserId = user.id;
            
            // Fill form with user data
            document.getElementById('userName').value = user.name;
            document.getElementById('userEmail').value = user.email;
            document.getElementById('userRole').value = user.role;
            document.getElementById('userDepartment').value = user.department_id || '';
            document.getElementById('userStatus').value = user.is_active ? '1' : '0';
            
            // Hide password field in edit mode
            passwordGroup.style.display = 'none';
            statusGroup.style.display = 'block';
            
            // Remove required attribute from password
            document.getElementById('userPassword').required = false;
        } else {
            // Add mode
            title.textContent = 'Add User';
            this.currentUserId = null;
            
            // Show password field in add mode
            passwordGroup.style.display = 'block';
            statusGroup.style.display = 'none';
            
            // Add required attribute to password
            document.getElementById('userPassword').required = true;
        }
        
        this.showModal(modal);
    }

    /**
     * Handle user form submission
     * @param {Event} e Form submit event
     */
    async handleUserFormSubmit(e) {
        e.preventDefault();
        
        const form = e.target;
        const formData = new FormData(form);
        const userData = {};
        
        // Convert FormData to object
        for (let [key, value] of formData.entries()) {
            if (key === 'department_id' && value === '') {
                userData[key] = null;
            } else {
                userData[key] = value;
            }
        }
        
        try {
            let response;
            
            if (this.currentUserId) {
                // Update existing user
                response = await api.request('PUT', `users.php?id=${this.currentUserId}`, userData);
            } else {
                // Create new user
                response = await api.request('POST', 'users.php', userData);
            }
            
            if (response.success) {
                api.showToast(
                    this.currentUserId ? 'User updated successfully' : 'User created successfully',
                    'success'
                );
                this.hideModal(document.getElementById('userModal'));
                await this.loadUsers();
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('User form submission failed:', error);
            api.showToast(error.message, 'error');
        }
    }

    /**
     * Edit user
     * @param {number} userId User ID
     */
    async editUser(userId) {
        try {
            const response = await api.request('GET', `users.php?id=${userId}`);
            if (response.success) {
                this.showUserModal(response.data);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load user:', error);
            api.showToast('Failed to load user details', 'error');
        }
    }

    /**
     * Reset user password
     * @param {number} userId User ID
     */
    resetUserPassword(userId) {
        this.currentUserId = userId;
        const modal = document.getElementById('passwordResetModal');
        document.getElementById('passwordResetForm').reset();
        this.showModal(modal);
    }

    /**
     * Handle password reset form submission
     * @param {Event} e Form submit event
     */
    async handlePasswordReset(e) {
        e.preventDefault();
        
        const form = e.target;
        const newPassword = form.new_password.value;
        const confirmPassword = form.confirm_password.value;
        
        if (newPassword !== confirmPassword) {
            api.showToast('Passwords do not match', 'error');
            return;
        }
        
        try {
            const response = await api.request('POST', `users.php?id=${this.currentUserId}&action=reset_password`, {
                new_password: newPassword
            });
            
            if (response.success) {
                api.showToast('Password reset successfully', 'success');
                this.hideModal(document.getElementById('passwordResetModal'));
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Password reset failed:', error);
            api.showToast(error.message, 'error');
        }
    }

    /**
     * Deactivate user
     * @param {number} userId User ID
     */
    async deactivateUser(userId) {
        if (!confirm('Are you sure you want to deactivate this user?')) {
            return;
        }
        
        try {
            const response = await api.request('DELETE', `users.php?id=${userId}`);
            if (response.success) {
                api.showToast('User deactivated successfully', 'success');
                await this.loadUsers();
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to deactivate user:', error);
            api.showToast(error.message, 'error');
        }
    }

    /**
     * Activate user
     * @param {number} userId User ID
     */
    async activateUser(userId) {
        try {
            const response = await api.request('PUT', `users.php?id=${userId}`, {
                is_active: true
            });
            
            if (response.success) {
                api.showToast('User activated successfully', 'success');
                await this.loadUsers();
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to activate user:', error);
            api.showToast(error.message, 'error');
        }
    }

    /**
     * Load departments for user form dropdown
     */
    async loadDepartmentsForUserForm() {
        try {
            const departments = await api.getDepartments();
            const select = document.getElementById('userDepartment');
            
            if (select) {
                // Clear existing options except the first one
                while (select.children.length > 1) {
                    select.removeChild(select.lastChild);
                }
                
                // Add department options
                departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.id;
                    option.textContent = dept.name;
                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Failed to load departments for form:', error);
        }
    }

    // =============================================
    // ASSET MANAGEMENT METHODS
    // =============================================

    /**
     * Show asset management interface
     */
    async showAssetManagement() {
        if (!this.hasAssetPermission()) {
            api.showToast('Access denied: Insufficient permissions', 'error');
            return;
        }
        
        this.showView('assetManagement');
        await this.loadAssets();
        await this.loadAssetFilters();
    }

    /**
     * Check if current user has asset management permissions
     * @returns {boolean}
     */
    hasAssetPermission() {
        return this.currentUser && ['superadmin', 'admin', 'auditor', 'dept_manager'].includes(this.currentUser.role);
    }

    /**
     * Load assets list with filters
     */
    async loadAssets() {
        try {
            const filters = this.getAssetFilters();
            const response = await api.getAssets(filters);
            
            if (response.success) {
                this.assetsData = response.data.assets;
                this.renderAssetsTable(response.data.assets);
                this.renderAssetsPagination(response.data.pagination);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load assets:', error);
            api.showToast('Failed to load assets', 'error');
        }
    }

    /**
     * Get current asset filter values
     * @returns {Object} Filter parameters
     */
    getAssetFilters() {
        return {
            page: this.assetCurrentPage,
            limit: this.assetsPerPage,
            search: document.getElementById('assetSearch')?.value || '',
            asset_type_id: document.getElementById('assetTypeFilter')?.value || '',
            status: document.getElementById('assetStatusFilter')?.value || '',
            department_id: document.getElementById('assetDepartmentFilter')?.value || ''
        };
    }

    /**
     * Filter assets based on current criteria
     */
    async filterAssets() {
        this.assetCurrentPage = 1;
        await this.loadAssets();
    }

    /**
     * Load filter options for assets
     */
    async loadAssetFilters() {
        try {
            // Load departments
            const departments = await api.getDepartments();
            const deptSelect = document.getElementById('assetDepartmentFilter');
            if (deptSelect) {
                while (deptSelect.children.length > 1) {
                    deptSelect.removeChild(deptSelect.lastChild);
                }
                departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.id;
                    option.textContent = dept.name;
                    deptSelect.appendChild(option);
                });
            }

            // Load asset types for all departments
            const allAssetTypes = [];
            for (const dept of departments) {
                const assetTypes = await api.getAssetTypes(dept.id);
                allAssetTypes.push(...assetTypes.map(at => ({ ...at, department_name: dept.name })));
            }

            const typeSelect = document.getElementById('assetTypeFilter');
            if (typeSelect) {
                while (typeSelect.children.length > 1) {
                    typeSelect.removeChild(typeSelect.lastChild);
                }
                allAssetTypes.forEach(type => {
                    const option = document.createElement('option');
                    option.value = type.id;
                    option.textContent = `${type.name} (${type.department_name})`;
                    typeSelect.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Failed to load asset filters:', error);
        }
    }

    /**
     * Render assets table
     * @param {Array} assets List of assets
     */
    renderAssetsTable(assets) {
        const tbody = document.getElementById('assetsTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        assets.forEach(asset => {
            const row = document.createElement('tr');
            const maintenanceStatus = this.getMaintenanceStatus(asset.days_to_maintenance);
            
            row.innerHTML = `
                <td>
                    <div class="asset-info">
                        <div class="asset-name">${api.sanitizeHTML(asset.name)}</div>
                        <div class="asset-details">
                            ${asset.asset_tag ? `<span class="asset-tag">${api.sanitizeHTML(asset.asset_tag)}</span>` : ''}
                            ${asset.model ? `<span class="asset-model">📱 ${api.sanitizeHTML(asset.model)}</span>` : ''}
                            ${asset.serial_no ? `<span class="asset-serial">#${api.sanitizeHTML(asset.serial_no)}</span>` : ''}
                        </div>
                    </div>
                </td>
                <td>
                    <div class="asset-type-info">
                        <div class="type-name">${api.sanitizeHTML(asset.asset_type_name)}</div>
                        <div class="dept-name">${api.sanitizeHTML(asset.department_name)}</div>
                    </div>
                </td>
                <td>${api.sanitizeHTML(asset.location || 'N/A')}</td>
                <td>
                    <span class="status-badge ${asset.status}">
                        ${this.formatAssetStatus(asset.status)}
                    </span>
                </td>
                <td>
                    ${asset.next_maintenance ? 
                        `<span class="maintenance-info ${maintenanceStatus.class}">
                            ${asset.next_maintenance}
                            <small>${maintenanceStatus.text}</small>
                        </span>` : 
                        'N/A'
                    }
                </td>
                <td>
                    ${asset.open_ncrs > 0 ? 
                        `<span class="ncr-count warning">${asset.open_ncrs} Open</span>` : 
                        '<span class="ncr-count success">None</span>'
                    }
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-icon" onclick="app.viewAsset(${asset.id})" title="View Details">
                            👁️
                        </button>
                        <button class="btn-icon" onclick="app.editAsset(${asset.id})" title="Edit Asset">
                            ✏️
                        </button>
                        ${asset.status === 'active' ? 
                            `<button class="btn-icon danger" onclick="app.decommissionAsset(${asset.id})" title="Decommission">
                                🗑️
                            </button>` : ''
                        }
                    </div>
                </td>
            `;
            tbody.appendChild(row);
        });
    }

    /**
     * Get maintenance status information
     * @param {number} daysToMaintenance Days until maintenance
     * @returns {Object} Status class and text
     */
    getMaintenanceStatus(daysToMaintenance) {
        if (daysToMaintenance === null || daysToMaintenance === undefined) {
            return { class: '', text: '' };
        }
        
        if (daysToMaintenance < 0) {
            return { class: 'overdue', text: `${Math.abs(daysToMaintenance)} days overdue` };
        } else if (daysToMaintenance <= 7) {
            return { class: 'due-soon', text: `Due in ${daysToMaintenance} days` };
        } else {
            return { class: 'scheduled', text: `${daysToMaintenance} days remaining` };
        }
    }

    /**
     * Format asset status for display
     * @param {string} status Asset status
     * @returns {string} Formatted status
     */
    formatAssetStatus(status) {
        const statusMap = {
            'active': 'Active',
            'inactive': 'Inactive',
            'maintenance': 'Under Maintenance',
            'decommissioned': 'Decommissioned'
        };
        return statusMap[status] || status;
    }

    /**
     * Render pagination for assets
     * @param {Object} pagination Pagination data
     */
    renderAssetsPagination(pagination) {
        const container = document.getElementById('assetsPagination');
        if (!container) return;
        
        let html = '<div class="pagination-info">';
        html += `Showing ${((pagination.page - 1) * pagination.limit) + 1}-${Math.min(pagination.page * pagination.limit, pagination.total)} of ${pagination.total} assets`;
        html += '</div>';
        
        if (pagination.pages > 1) {
            html += '<div class="pagination-controls">';
            
            if (pagination.page > 1) {
                html += `<button class="btn btn-secondary" onclick="app.goToAssetPage(${pagination.page - 1})">Previous</button>`;
            }
            
            const startPage = Math.max(1, pagination.page - 2);
            const endPage = Math.min(pagination.pages, pagination.page + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === pagination.page ? 'active' : '';
                html += `<button class="btn btn-secondary ${activeClass}" onclick="app.goToAssetPage(${i})">${i}</button>`;
            }
            
            if (pagination.page < pagination.pages) {
                html += `<button class="btn btn-secondary" onclick="app.goToAssetPage(${pagination.page + 1})">Next</button>`;
            }
            
            html += '</div>';
        }
        
        container.innerHTML = html;
    }

    /**
     * Go to specific asset page
     * @param {number} page Page number
     */
    async goToAssetPage(page) {
        this.assetCurrentPage = page;
        await this.loadAssets();
    }

    /**
     * Show asset modal for adding/editing
     * @param {Object} asset Asset data for editing (null for new asset)
     */
    async showAssetModal(asset = null) {
        const modal = document.getElementById('assetModal');
        const form = document.getElementById('assetForm');
        const title = document.getElementById('assetModalTitle');
        
        // Reset form
        form.reset();
        
        // Load asset types for form
        await this.loadAssetTypesForForm();
        
        if (asset) {
            // Edit mode
            title.textContent = 'Edit Asset';
            this.currentAssetId = asset.id;
            
            // Fill form with asset data
            document.getElementById('assetName').value = asset.name || '';
            document.getElementById('assetTag').value = asset.asset_tag || '';
            document.getElementById('assetTypeSelect').value = asset.asset_type_id || '';
            document.getElementById('assetStatus').value = asset.status || 'active';
            document.getElementById('assetModel').value = asset.model || '';
            document.getElementById('assetSerial').value = asset.serial_no || '';
            document.getElementById('assetLocation').value = asset.location || '';
            document.getElementById('assetVendor').value = asset.vendor || '';
            document.getElementById('assetInstallDate').value = asset.installation_date || '';
            document.getElementById('assetWarrantyEnd').value = asset.warranty_end || '';
            document.getElementById('assetCalibrationDate').value = asset.next_calibration_date || '';
        } else {
            // Add mode
            title.textContent = 'Add Asset';
            this.currentAssetId = null;
        }
        
        this.showModal(modal);
    }

    /**
     * Load asset types for form dropdown
     */
    async loadAssetTypesForForm() {
        try {
            const departments = await api.getDepartments();
            const select = document.getElementById('assetTypeSelect');
            
            if (select) {
                // Clear existing options except the first one
                while (select.children.length > 1) {
                    select.removeChild(select.lastChild);
                }
                
                // Load asset types for each department
                for (const dept of departments) {
                    const assetTypes = await api.getAssetTypes(dept.id);
                    if (assetTypes.length > 0) {
                        const optgroup = document.createElement('optgroup');
                        optgroup.label = dept.name;
                        
                        assetTypes.forEach(type => {
                            const option = document.createElement('option');
                            option.value = type.id;
                            option.textContent = type.name;
                            optgroup.appendChild(option);
                        });
                        
                        select.appendChild(optgroup);
                    }
                }
            }
        } catch (error) {
            console.error('Failed to load asset types for form:', error);
        }
    }

    /**
     * Handle asset form submission
     * @param {Event} e Form submit event
     */
    async handleAssetFormSubmit(e) {
        e.preventDefault();
        
        const form = e.target;
        const formData = new FormData(form);
        const assetData = {};
        
        // Convert FormData to object
        for (let [key, value] of formData.entries()) {
            if (value === '') {
                assetData[key] = null;
            } else {
                assetData[key] = value;
            }
        }
        
        try {
            let response;
            
            if (this.currentAssetId) {
                // Update existing asset
                response = await api.updateAsset(this.currentAssetId, assetData);
            } else {
                // Create new asset
                response = await api.createAsset(assetData);
            }
            
            if (response.success) {
                api.showToast(
                    this.currentAssetId ? 'Asset updated successfully' : 'Asset created successfully',
                    'success'
                );
                this.hideModal(document.getElementById('assetModal'));
                await this.loadAssets();
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Asset form submission failed:', error);
            api.showToast(error.message, 'error');
        }
    }

    /**
     * View asset details
     * @param {number} assetId Asset ID
     */
    async viewAsset(assetId) {
        try {
            const response = await api.getAssetById(assetId);
            if (response.success) {
                // Show asset details in a modal or navigate to details view
                this.showAssetDetailsModal(response.data);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load asset details:', error);
            api.showToast('Failed to load asset details', 'error');
        }
    }

    /**
     * Decommission asset
     * @param {number} assetId Asset ID
     */
    async decommissionAsset(assetId) {
        if (!confirm('Are you sure you want to decommission this asset?')) {
            return;
        }
        
        try {
            const response = await api.request('DELETE', `assets.php?id=${assetId}`);
            if (response.success) {
                api.showToast('Asset decommissioned successfully', 'success');
                await this.loadAssets();
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to decommission asset:', error);
            api.showToast(error.message, 'error');
        }
    }

    /**
     * Show asset details modal
     * @param {Object} asset Asset data
     */
    showAssetDetailsModal(asset) {
        const modal = document.getElementById('assetDetailsModal');
        const detailsContainer = document.getElementById('assetDetailsContainer');
        
        detailsContainer.innerHTML = `
            <div class="asset-detail">
                <strong>Name:</strong> ${api.sanitizeHTML(asset.name)}
            </div>
            <div class="asset-detail">
                <strong>Asset Tag:</strong> ${api.sanitizeHTML(asset.asset_tag || 'N/A')}
            </div>
            <div class="asset-detail">
                <strong>Model:</strong> ${api.sanitizeHTML(asset.model || 'N/A')}
            </div>
            <div class="asset-detail">
                <strong>Serial No:</strong> ${api.sanitizeHTML(asset.serial_no || 'N/A')}
            </div>
            <div class="asset-detail">
                <strong>Location:</strong> ${api.sanitizeHTML(asset.location || 'N/A')}
            </div>
            <div class="asset-detail">
                <strong>Vendor:</strong> ${api.sanitizeHTML(asset.vendor || 'N/A')}
            </div>
            <div class="asset-detail">
                <strong>Installation Date:</strong> ${asset.installation_date || 'N/A'}
            </div>
            <div class="asset-detail">
                <strong>Warranty End:</strong> ${asset.warranty_end || 'N/A'}
            </div>
            <div class="asset-detail">
                <strong>Next Calibration Date:</strong> ${asset.next_calibration_date || 'N/A'}
            </div>
            <div class="asset-detail">
                <strong>Status:</strong> ${this.formatAssetStatus(asset.status)}
            </div>
            <div class="asset-detail">
                <strong>Asset Type:</strong> ${api.sanitizeHTML(asset.asset_type_name)}
            </div>
            <div class="asset-detail">
                <strong>Department:</strong> ${api.sanitizeHTML(asset.department_name)}
            </div>
            <div class="asset-detail">
                <strong>Next Maintenance:</strong> ${asset.next_maintenance || 'N/A'}
            </div>
            <div class="asset-detail">
                <strong>Open NCRs:</strong> ${asset.open_ncrs || 0}
            </div>
        `;
        
        this.showModal(modal);
    }

    // =============================================
    // NCR MANAGEMENT METHODS
    // =============================================

    /**
     * Show NCR management interface
     */
    async showNCRManagement() {
        if (!this.hasNCRPermission()) {
            api.showToast('Access denied: Insufficient permissions', 'error');
            return;
        }
        
        this.showView('ncrManagement');
        await this.loadNCRs();
        await this.loadNCRFilters();
    }

    /**
     * Check if current user has NCR management permissions
     * @returns {boolean}
     */
    hasNCRPermission() {
        return this.currentUser && ['superadmin', 'admin', 'auditor', 'dept_manager'].includes(this.currentUser.role);
    }

    /**
     * Load NCRs list with filters
     */
    async loadNCRs() {
        try {
            const filters = this.getNCRFilters();
            const response = await api.getNCRs(filters);
            
            if (response.success) {
                this.ncrsData = response.data.ncrs;
                this.renderNCRsTable(response.data.ncrs);
                this.renderNCRsPagination(response.data.pagination);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load NCRs:', error);
            api.showToast('Failed to load NCRs', 'error');
        }
    }

    /**
     * Get current NCR filter values
     * @returns {Object} Filter parameters
     */
    getNCRFilters() {
        return {
            page: this.ncrCurrentPage,
            limit: this.ncrsPerPage,
            search: document.getElementById('ncrSearch')?.value || '',
            status: document.getElementById('ncrStatusFilter')?.value || '',
            department_id: document.getElementById('ncrDepartmentFilter')?.value || ''
        };
    }

    /**
     * Filter NCRs based on current criteria
     */
    async filterNCRs() {
        this.ncrCurrentPage = 1;
        await this.loadNCRs();
    }

    /**
     * Load filter options for NCRs
     */
    async loadNCRFilters() {
        try {
            // Load departments
            const departments = await api.getDepartments();
            const deptSelect = document.getElementById('ncrDepartmentFilter');
            if (deptSelect) {
                while (deptSelect.children.length > 1) {
                    deptSelect.removeChild(deptSelect.lastChild);
                }
                departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.id;
                    option.textContent = dept.name;
                    deptSelect.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Failed to load NCR filters:', error);
        }
    }

    /**
     * Render NCRs table
     * @param {Array} ncrs List of NCRs
     */
    renderNCRsTable(ncrs) {
        const tbody = document.getElementById('ncrsTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        ncrs.forEach(ncr => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <div class="ncr-info">
                        <div class="ncr-id">#${ncr.id}</div>
                        <div class="ncr-title">${api.sanitizeHTML(ncr.title)}</div>
                    </div>
                </td>
                <td>${api.sanitizeHTML(ncr.department_name)}</td>
                <td>${api.sanitizeHTML(ncr.asset_name || 'N/A')}</td>
                <td>${api.sanitizeHTML(ncr.category)}</td>
                <td>${api.sanitizeHTML(ncr.severity)}</td>
                <td>${new Date(ncr.created_at).toLocaleDateString()}</td>
                <td>
                    <span class="status-badge ${ncr.status}">
                        ${this.formatNCRStatus(ncr.status)}
                    </span>
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-icon" onclick="app.viewNCR(${ncr.id})" title="View Details">
                            👁️
                        </button>
                        <button class="btn-icon" onclick="app.editNCR(${ncr.id})" title="Edit NCR">
                            ✏️
                        </button>
                        ${ncr.status === 'open' ? 
                            `<button class="btn-icon danger" onclick="app.closeNCR(${ncr.id})" title="Close NCR">
                                ✖️
                            </button>` : ''
                        }
                    </div>
                </td>
            `;
            tbody.appendChild(row);
        });
    }

    /**
     * Format NCR status for display
     * @param {string} status NCR status
     * @returns {string} Formatted status
     */
    formatNCRStatus(status) {
        const statusMap = {
            'open': 'Open',
            'closed': 'Closed',
            'resolved': 'Resolved',
            'pending': 'Pending'
        };
        return statusMap[status] || status;
    }

    /**
     * Render pagination for NCRs
     * @param {Object} pagination Pagination data
     */
    renderNCRsPagination(pagination) {
        const container = document.getElementById('ncrsPagination');
        if (!container) return;
        
        let html = '<div class="pagination-info">';
        html += `Showing ${((pagination.page - 1) * pagination.limit) + 1}-${Math.min(pagination.page * pagination.limit, pagination.total)} of ${pagination.total} NCRs`;
        html += '</div>';
        
        if (pagination.pages > 1) {
            html += '<div class="pagination-controls">';
            
            if (pagination.page > 1) {
                html += `<button class="btn btn-secondary" onclick="app.goToNCRPage(${pagination.page - 1})">Previous</button>`;
            }
            
            const startPage = Math.max(1, pagination.page - 2);
            const endPage = Math.min(pagination.pages, pagination.page + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === pagination.page ? 'active' : '';
                html += `<button class="btn btn-secondary ${activeClass}" onclick="app.goToNCRPage(${i})">${i}</button>`;
            }
            
            if (pagination.page < pagination.pages) {
                html += `<button class="btn btn-secondary" onclick="app.goToNCRPage(${pagination.page + 1})">Next</button>`;
            }
            
            html += '</div>';
        }
        
        container.innerHTML = html;
    }

    /**
     * Go to specific NCR page
     * @param {number} page Page number
     */
    async goToNCRPage(page) {
        this.ncrCurrentPage = page;
        await this.loadNCRs();
    }

    // =============================================
    // REPORTS & ANALYTICS METHODS
    // =============================================

    /**
     * Show reports and analytics interface
     */
    async showReports() {
        if (!this.hasReportsPermission()) {
            api.showToast('Access denied: Insufficient permissions', 'error');
            return;
        }
        
        this.showView('reports');
        await this.loadReportsData();
        this.bindReportsEvents();
    }

    /**
     * Check if current user has reports permission
     * @returns {boolean}
     */
    hasReportsPermission() {
        return this.currentUser && ['superadmin', 'admin', 'auditor', 'dept_manager'].includes(this.currentUser.role);
    }

    /**
     * Bind reports-specific event handlers
     */
    bindReportsEvents() {
        // Date range change handler
        document.getElementById('reportsDateRange')?.addEventListener('change', (e) => {
            this.handleDateRangeChange(e.target.value);
        });

        // Department filter change
        document.getElementById('reportsDepartmentFilter')?.addEventListener('change', () => {
            this.loadReportsData();
        });

        // Export button
        document.getElementById('exportReportBtn')?.addEventListener('click', () => {
            this.exportCurrentReport();
        });
    }

    /**
     * Handle date range selection change
     * @param {string} range Selected range value
     */
    handleDateRangeChange(range) {
        const fromInput = document.getElementById('reportsDateFrom');
        const toInput = document.getElementById('reportsDateTo');
        
        if (range === 'custom') {
            fromInput.style.display = 'block';
            toInput.style.display = 'block';
            return;
        }
        
        fromInput.style.display = 'none';
        toInput.style.display = 'none';
        
        // Set predefined date ranges
        const now = new Date();
        let fromDate, toDate;
        
        switch (range) {
            case 'thisMonth':
                fromDate = new Date(now.getFullYear(), now.getMonth(), 1);
                toDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'lastMonth':
                fromDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                toDate = new Date(now.getFullYear(), now.getMonth(), 0);
                break;
            case 'last3Months':
                fromDate = new Date(now.getFullYear(), now.getMonth() - 3, 1);
                toDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'last6Months':
                fromDate = new Date(now.getFullYear(), now.getMonth() - 6, 1);
                toDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'thisYear':
                fromDate = new Date(now.getFullYear(), 0, 1);
                toDate = new Date(now.getFullYear(), 11, 31);
                break;
            default:
                fromDate = new Date(now.getFullYear(), now.getMonth(), 1);
                toDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        }
        
        fromInput.value = fromDate.toISOString().split('T')[0];
        toInput.value = toDate.toISOString().split('T')[0];
        
        this.loadReportsData();
    }

    /**
     * Get current report filters
     * @returns {Object} Filter parameters
     */
    getReportFilters() {
        const dateFrom = document.getElementById('reportsDateFrom')?.value;
        const dateTo = document.getElementById('reportsDateTo')?.value;
        const departmentId = document.getElementById('reportsDepartmentFilter')?.value;
        
        const filters = {};
        
        if (dateFrom) filters.date_from = dateFrom;
        if (dateTo) filters.date_to = dateTo;
        if (departmentId) filters.department_id = departmentId;
        
        return filters;
    }

    /**
     * Load all reports data
     */
    async loadReportsData() {
        try {
            const filters = this.getReportFilters();
            
            // Load dashboard analytics
            const analyticsResponse = await api.getDashboardAnalytics(filters);
            if (analyticsResponse.success) {
                this.renderAnalyticsCards(analyticsResponse.data);
                this.renderComplianceTrends(analyticsResponse.data.trends);
            }
            
            // Load compliance report
            const complianceResponse = await api.getComplianceReport(filters);
            if (complianceResponse.success) {
                this.renderDepartmentComplianceTable(complianceResponse.data.department_compliance);
            }
            
            // Load NCR analysis
            const ncrAnalysisResponse = await api.getNCRAnalysis(filters);
            if (ncrAnalysisResponse.success) {
                this.renderNCRAnalysisCharts(ncrAnalysisResponse.data);
            }
            
            // Load departments for filter
            await this.loadReportsDepartmentFilter();
            
        } catch (error) {
            console.error('Failed to load reports data:', error);
            api.showToast('Failed to load reports data', 'error');
        }
    }

    /**
     * Render analytics cards with metrics
     * @param {Object} data Analytics data
     */
    renderAnalyticsCards(data) {
        const metrics = data.metrics;
        
        // Update period label
        document.getElementById('complianceOverviewPeriod').textContent = 
            `${data.period.from} to ${data.period.to}`;
        
        // Compliance overview
        document.getElementById('overallComplianceRate').textContent = `${metrics.compliance_rate}%`;
        document.getElementById('completedChecklistsCount').textContent = metrics.completed_checklists;
        document.getElementById('pendingChecklistsCount').textContent = metrics.pending_checklists;
        document.getElementById('overdueChecklistsCount').textContent = metrics.overdue_checklists;
        
        // Asset status
        document.getElementById('activeAssetsCount').textContent = metrics.active_assets;
        document.getElementById('maintenanceDueCount').textContent = metrics.maintenance_due;
        document.getElementById('overdueMaintenanceCount').textContent = metrics.overdue_maintenance;
        
        // NCR summary
        document.getElementById('totalNCRsCount').textContent = metrics.total_ncrs;
        document.getElementById('openNCRsCount').textContent = metrics.open_ncrs;
        document.getElementById('criticalNCRsCount').textContent = metrics.critical_ncrs;
    }

    /**
     * Render compliance trends chart (simplified text-based for now)
     * @param {Array} trends Trend data
     */
    renderComplianceTrends(trends) {
        const container = document.getElementById('complianceTrendsChart');
        
        let html = '<div class="trends-list">';
        trends.forEach(trend => {
            const trendClass = trend.compliance_rate >= 90 ? 'success' : 
                              trend.compliance_rate >= 75 ? 'warning' : 'danger';
            
            html += `
                <div class="trend-item ${trendClass}">
                    <span class="trend-month">${trend.month}</span>
                    <span class="trend-rate">${trend.compliance_rate}%</span>
                    <span class="trend-details">${trend.completed_checklists}/${trend.total_checklists}</span>
                </div>
            `;
        });
        html += '</div>';
        
        container.innerHTML = html;
    }

    /**
     * Render department compliance table
     * @param {Array} departments Department compliance data
     */
    renderDepartmentComplianceTable(departments) {
        const tbody = document.getElementById('departmentComplianceTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        departments.forEach(dept => {
            const row = document.createElement('tr');
            const complianceClass = dept.compliance_rate >= 90 ? 'success' : 
                                   dept.compliance_rate >= 75 ? 'warning' : 'danger';
            
            row.innerHTML = `
                <td>${api.sanitizeHTML(dept.department_name)}</td>
                <td>${dept.total_checklists}</td>
                <td>${dept.completed_checklists}</td>
                <td>${dept.pending_checklists}</td>
                <td>${dept.overdue_checklists}</td>
                <td>
                    <span class="compliance-rate ${complianceClass}">
                        ${dept.compliance_rate || 0}%
                    </span>
                </td>
            `;
            tbody.appendChild(row);
        });
    }

    /**
     * Render NCR analysis charts (simplified for now)
     * @param {Object} data NCR analysis data
     */
    renderNCRAnalysisCharts(data) {
        // Severity chart
        const severityContainer = document.getElementById('ncrSeverityChart');
        if (severityContainer && data.severity_breakdown) {
            let html = '<div class="severity-list">';
            data.severity_breakdown.forEach(item => {
                const severityClass = item.severity;
                html += `
                    <div class="severity-item ${severityClass}">
                        <span class="severity-label">${item.severity.toUpperCase()}</span>
                        <span class="severity-count">${item.count}</span>
                    </div>
                `;
            });
            html += '</div>';
            severityContainer.innerHTML = html;
        }
        
        // Category chart
        const categoryContainer = document.getElementById('ncrCategoryChart');
        if (categoryContainer && data.category_breakdown) {
            let html = '<div class="category-list">';
            data.category_breakdown.forEach(item => {
                html += `
                    <div class="category-item">
                        <span class="category-label">${api.sanitizeHTML(item.category)}</span>
                        <span class="category-count">${item.count}</span>
                    </div>
                `;
            });
            html += '</div>';
            categoryContainer.innerHTML = html;
        }
    }

    /**
     * Load departments for reports filter
     */
    async loadReportsDepartmentFilter() {
        try {
            const departments = await api.getDepartments();
            const select = document.getElementById('reportsDepartmentFilter');
            
            if (select) {
                // Clear existing options except the first one
                while (select.children.length > 1) {
                    select.removeChild(select.lastChild);
                }
                
                departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.id;
                    option.textContent = dept.name;
                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Failed to load departments for reports filter:', error);
        }
    }

    /**
     * Load detailed compliance report
     */
    async loadDetailedComplianceReport() {
        try {
            const filters = this.getReportFilters();
            const response = await api.getComplianceReport(filters);
            
            if (response.success) {
                this.renderDepartmentComplianceTable(response.data.department_compliance);
                api.showToast('Compliance report refreshed', 'success');
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load detailed compliance report:', error);
            api.showToast('Failed to refresh compliance report', 'error');
        }
    }

    /**
     * Load NCR analysis report
     */
    async loadNCRAnalysisReport() {
        try {
            const filters = this.getReportFilters();
            const response = await api.getNCRAnalysis(filters);
            
            if (response.success) {
                this.renderNCRAnalysisCharts(response.data);
                api.showToast('NCR analysis refreshed', 'success');
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load NCR analysis report:', error);
            api.showToast('Failed to refresh NCR analysis', 'error');
        }
    }

    /**
     * Export current report data
     */
    async exportCurrentReport() {
        try {
            const filters = this.getReportFilters();
            const exportConfig = {
                type: 'comprehensive',
                format: 'pdf',
                filters: filters,
                sections: ['compliance', 'assets', 'ncrs', 'trends']
            };
            
            api.showToast('Export functionality will be implemented in future version', 'info');
            
            // Future implementation:
            // const response = await api.exportReport(exportConfig);
            // if (response.success) {
            //     // Download the exported file
            //     window.open(response.data.download_url, '_blank');
            // }
        } catch (error) {
            console.error('Failed to export report:', error);
            api.showToast('Failed to export report', 'error');
        }
    }
}
        }
    }

    /**
     * Edit asset
     * @param {number} assetId Asset ID
     */
    async editAsset(assetId) {
        try {
            const response = await api.getAssetById(assetId);
            if (response.success) {
                this.showAssetModal(response.data);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load asset:', error);
            api.showToast('Failed to load asset details', 'error');
        }
    }

    /**
     * Decommission asset
     * @param {number} assetId Asset ID
     */
    async decommissionAsset(assetId) {
        if (!confirm('Are you sure you want to decommission this asset? This action cannot be undone.')) {
            return;
        }
        
        try {
            const response = await api.deleteAsset(assetId);
            if (response.success) {
                api.showToast('Asset decommissioned successfully', 'success');
                await this.loadAssets();
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to decommission asset:', error);
            api.showToast(error.message, 'error');
        }
    }

    /**
     * Show asset details in modal
     * @param {Object} asset Asset object
     */
    showAssetDetailsModal(asset) {
        // Placeholder for asset details modal
        // In a full implementation, this would show comprehensive asset information
        api.showToast(`Asset Details: ${asset.name} (${asset.asset_tag})`, 'info', 3000);
    }

    // =============================================
    // NCR MANAGEMENT METHODS
    // =============================================

    /**
     * Show NCR management interface
     */
    async showNCRManagement() {
        if (!this.hasNCRPermission()) {
            api.showToast('Access denied: Insufficient permissions', 'error');
            return;
        }
        
        this.showView('ncrManagement');
        await this.loadNCRs();
        await this.loadNCRFilters();
    }

    /**
     * Check if current user has NCR management permissions
     * @returns {boolean}
     */
    hasNCRPermission() {
        return this.currentUser && ['superadmin', 'admin', 'auditor', 'dept_manager', 'technician'].includes(this.currentUser.role);
    }

    /**
     * Load NCRs list with filters
     */
    async loadNCRs() {
        try {
            const filters = this.getNCRFilters();
            const response = await api.getNCRs(filters);
            
            if (response.success) {
                this.ncrsData = response.data.ncrs;
                this.renderNCRsTable(response.data.ncrs);
                this.renderNCRsPagination(response.data.pagination);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load NCRs:', error);
            api.showToast('Failed to load NCRs', 'error');
        }
    }

    /**
     * Get current NCR filter values
     * @returns {Object} Filter parameters
     */
    getNCRFilters() {
        return {
            page: this.ncrCurrentPage,
            limit: this.ncrsPerPage,
            search: document.getElementById('ncrSearch')?.value || '',
            status: document.getElementById('ncrStatusFilter')?.value || '',
            severity: document.getElementById('ncrSeverityFilter')?.value || '',
            department_id: document.getElementById('ncrDepartmentFilter')?.value || ''
        };
    }

    /**
     * Filter NCRs
     */
    async filterNCRs() {
        this.ncrCurrentPage = 1;
        await this.loadNCRs();
    }

    /**
     * Load filter options for NCR management
     */
    async loadNCRFilters() {
        try {
            const departments = await api.getDepartments();
            
            // Load department filter
            const deptFilter = document.getElementById('ncrDepartmentFilter');
            if (deptFilter) {
                // Clear existing options except the first one
                while (deptFilter.children.length > 1) {
                    deptFilter.removeChild(deptFilter.lastChild);
                }
                
                // Add department options
                departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.id;
                    option.textContent = dept.name;
                    deptFilter.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Failed to load NCR filters:', error);
        }
    }

    /**
     * Render NCRs table
     * @param {Array} ncrs List of NCRs
     */
    renderNCRsTable(ncrs) {
        const tbody = document.getElementById('ncrTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        if (ncrs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center">No NCRs found</td></tr>';
            return;
        }
        
        ncrs.forEach(ncr => {
            const row = document.createElement('tr');
            
            row.innerHTML = `
                <td><span class="ncr-id">NCR-${String(ncr.id).padStart(4, '0')}</span></td>
                <td>
                    <div class="ncr-title">${api.sanitizeHTML(ncr.title)}</div>
                    <div class="ncr-category">${api.sanitizeHTML(ncr.category)}</div>
                </td>
                <td>${api.sanitizeHTML(ncr.department_name)}</td>
                <td><span class="ncr-severity ${ncr.severity}">${ncr.severity.toUpperCase()}</span></td>
                <td><span class="ncr-status ${ncr.status}">${this.formatNCRStatus(ncr.status)}</span></td>
                <td>${api.sanitizeHTML(ncr.assigned_to_name || 'Unassigned')}</td>
                <td>${api.formatDate(ncr.created_at)}</td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-icon" onclick="app.viewNCR(${ncr.id})" title="View NCR">
                            👁️
                        </button>
                        <button class="btn-icon" onclick="app.editNCR(${ncr.id})" title="Edit NCR">
                            ✏️
                        </button>
                        <button class="btn-icon" onclick="app.manageNCRActions(${ncr.id})" title="Manage Actions">
                            ⚙️
                        </button>
                        ${ncr.status !== 'closed' ? 
                            `<button class="btn-icon danger" onclick="app.closeNCR(${ncr.id})" title="Close NCR">
                                ✖️
                            </button>` : ''
                        }
                    </div>
                </td>
            `;
            tbody.appendChild(row);
        });
    }

    /**
     * Format NCR status for display
     * @param {string} status NCR status
     * @returns {string} Formatted status
     */
    formatNCRStatus(status) {
        const statusMap = {
            'open': 'Open',
            'in_progress': 'In Progress',
            'under_review': 'Under Review',
            'closed': 'Closed'
        };
        return statusMap[status] || status;
    }

    /**
     * Show NCR modal for creation or editing
     * @param {Object} ncr NCR object for editing (null for creation)
     */
    async showNCRModal(ncr = null) {
        const modal = document.getElementById('ncrModal');
        const title = document.getElementById('ncrModalTitle');
        const form = document.getElementById('ncrForm');
        
        // Reset form
        form.reset();
        
        // Load departments and users for form
        await this.loadNCRFormOptions();
        
        if (ncr) {
            // Edit mode
            title.textContent = 'Edit NCR';
            this.currentNCRId = ncr.id;
            
            // Fill form with NCR data
            document.getElementById('ncrTitle').value = ncr.title || '';
            document.getElementById('ncrDepartment').value = ncr.department_id || '';
            document.getElementById('ncrSeverity').value = ncr.severity || '';
            document.getElementById('ncrCategory').value = ncr.category || '';
            document.getElementById('ncrDescription').value = ncr.description || '';
            document.getElementById('ncrImmediate').value = ncr.immediate_action || '';
            document.getElementById('ncrDetectedBy').value = ncr.detected_by || '';
            document.getElementById('ncrLocation').value = ncr.location || '';
            document.getElementById('ncrAssignedTo').value = ncr.assigned_to || '';
        } else {
            // Add mode
            title.textContent = 'Create NCR';
            this.currentNCRId = null;
        }
        
        this.showModal(modal);
    }

    /**
     * Load form options for NCR creation/editing
     */
    async loadNCRFormOptions() {
        try {
            // Load departments
            const departments = await api.getDepartments();
            const deptSelect = document.getElementById('ncrDepartment');
            if (deptSelect) {
                // Clear existing options except the first one
                while (deptSelect.children.length > 1) {
                    deptSelect.removeChild(deptSelect.lastChild);
                }
                
                departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.id;
                    option.textContent = dept.name;
                    deptSelect.appendChild(option);
                });
            }
            
            // Load users for assignment
            const usersResponse = await api.getUsers({ limit: 100 });
            const userSelect = document.getElementById('ncrAssignedTo');
            if (userSelect && usersResponse.success) {
                // Clear existing options except the first one
                while (userSelect.children.length > 1) {
                    userSelect.removeChild(userSelect.lastChild);
                }
                
                usersResponse.data.users.forEach(user => {
                    const option = document.createElement('option');
                    option.value = user.id;
                    option.textContent = `${user.name} (${user.email})`;
                    userSelect.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Failed to load NCR form options:', error);
        }
    }

    /**
     * Handle NCR form submission
     * @param {Event} e Form submit event
     */
    async handleNCRFormSubmit(e) {
        e.preventDefault();
        
        const form = e.target;
        const formData = new FormData(form);
        const ncrData = {};
        
        // Convert FormData to object
        for (let [key, value] of formData.entries()) {
            if (key === 'department_id' && value === '') {
                continue; // Skip empty department
            }
            if (key === 'assigned_to' && value === '') {
                continue; // Skip empty assignment
            }
            ncrData[key] = value;
        }
        
        try {
            let response;
            
            if (this.currentNCRId) {
                // Update existing NCR
                response = await api.updateNCR(this.currentNCRId, ncrData);
            } else {
                // Create new NCR
                response = await api.createNCR(ncrData);
            }
            
            if (response.success) {
                api.showToast(
                    this.currentNCRId ? 'NCR updated successfully' : 'NCR created successfully', 
                    'success'
                );
                this.hideModal(document.getElementById('ncrModal'));
                await this.loadNCRs();
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to save NCR:', error);
            api.showToast('Failed to save NCR', 'error');
        }
    }

    /**
     * View NCR details
     * @param {number} ncrId NCR ID
     */
    async viewNCR(ncrId) {
        try {
            const response = await api.getNCRById(ncrId);
            if (response.success) {
                this.showNCRDetailsModal(response.data);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load NCR:', error);
            api.showToast('Failed to load NCR details', 'error');
        }
    }

    /**
     * Edit NCR
     * @param {number} ncrId NCR ID
     */
    async editNCR(ncrId) {
        try {
            const response = await api.getNCRById(ncrId);
            if (response.success) {
                await this.showNCRModal(response.data);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to load NCR:', error);
            api.showToast('Failed to load NCR details', 'error');
        }
    }

    /**
     * Show NCR details modal
     * @param {Object} ncr NCR object
     */
    showNCRDetailsModal(ncr) {
        // For now, show as toast - can be expanded to full modal
        api.showToast(`NCR Details: ${ncr.title} - Status: ${this.formatNCRStatus(ncr.status)}`, 'info', 5000);
    }

    /**
     * Close NCR
     * @param {number} ncrId NCR ID
     */
    async closeNCR(ncrId) {
        if (!confirm('Are you sure you want to close this NCR?')) {
            return;
        }
        
        try {
            const response = await api.updateNCRStatus(ncrId, 'closed', 'NCR closed from management interface');
            
            if (response.success) {
                api.showToast('NCR closed successfully', 'success');
                await this.loadNCRs();
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            console.error('Failed to close NCR:', error);
            api.showToast('Failed to close NCR', 'error');
        }
    }

    /**
     * Render pagination for NCRs
     * @param {Object} pagination Pagination data
     */
    renderNCRsPagination(pagination) {
        const container = document.getElementById('ncrPagination');
        if (!container) return;
        
        let html = '<div class="pagination-info">';
        html += `Showing ${((pagination.page - 1) * pagination.limit) + 1}-${Math.min(pagination.page * pagination.limit, pagination.total)} of ${pagination.total} NCRs`;
        html += '</div>';
        
        if (pagination.pages > 1) {
            html += '<div class="pagination-controls">';
            
            if (pagination.page > 1) {
                html += `<button class="btn btn-secondary" onclick="app.goToNCRPage(${pagination.page - 1})">Previous</button>`;
            }
            
            const startPage = Math.max(1, pagination.page - 2);
            const endPage = Math.min(pagination.pages, pagination.page + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === pagination.page ? 'active' : '';
                html += `<button class="btn btn-secondary ${activeClass}" onclick="app.goToNCRPage(${i})">${i}</button>`;
            }
            
            if (pagination.page < pagination.pages) {
                html += `<button class="btn btn-secondary" onclick="app.goToNCRPage(${pagination.page + 1})">Next</button>`;
            }
            
            html += '</div>';
        }
        
        container.innerHTML = html;
    }

    /**
     * Go to specific NCR page
     * @param {number} page Page number
     */
    async goToNCRPage(page) {
        this.ncrCurrentPage = page;
        await this.loadNCRs();
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
