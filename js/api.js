/**
 * API Helper Module
 * Provides secure HTTP request handling for QuailtyMed frontend
 * Handles authentication, error handling, and data formatting
 */

/**
 * Base API configuration
 */
const API_CONFIG = {
    baseURL: 'api/',
    timeout: 10000,
    headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    }
};

/**
 * API Helper Class
 * Provides methods for making authenticated API requests
 */
class APIHelper {
    constructor() {
        this.isAuthenticated = false;
        this.currentUser = null;
        this.requestQueue = [];
        this.retryAttempts = 3;
    }

    /**
     * Make HTTP request with error handling and retry logic
     * @param {string} method HTTP method (GET, POST, PUT, DELETE)
     * @param {string} endpoint API endpoint
     * @param {Object} data Request data
     * @param {Object} options Additional options
     * @returns {Promise} API response
     */
    async request(method, endpoint, data = null, options = {}) {
        const config = {
            method: method.toUpperCase(),
            headers: { ...API_CONFIG.headers, ...options.headers },
            ...options
        };

        // Add body for POST/PUT requests
        if (data && ['POST', 'PUT', 'PATCH'].includes(config.method)) {
            if (data instanceof FormData) {
                // Remove Content-Type header for FormData (browser sets it)
                delete config.headers['Content-Type'];
                config.body = data;
            } else {
                config.body = JSON.stringify(data);
            }
        }

        // Add query parameters for GET requests
        let url = API_CONFIG.baseURL + endpoint;
        if (data && method.toUpperCase() === 'GET') {
            const params = new URLSearchParams(data);
            url += '?' + params.toString();
        }

        try {
            const response = await this.fetchWithTimeout(url, config);
            return await this.handleResponse(response);
        } catch (error) {
            return this.handleError(error, method, endpoint, data, options);
        }
    }

    /**
     * Fetch with timeout support
     * @param {string} url Request URL
     * @param {Object} config Fetch configuration
     * @returns {Promise} Fetch response
     */
    async fetchWithTimeout(url, config) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), API_CONFIG.timeout);

        try {
            const response = await fetch(url, {
                ...config,
                signal: controller.signal
            });
            clearTimeout(timeoutId);
            return response;
        } catch (error) {
            clearTimeout(timeoutId);
            throw error;
        }
    }

    /**
     * Handle API response
     * @param {Response} response Fetch response object
     * @returns {Promise} Parsed response data
     */
    async handleResponse(response) {
        let data;
        const contentType = response.headers.get('Content-Type');

        if (contentType && contentType.includes('application/json')) {
            data = await response.json();
        } else {
            data = await response.text();
        }

        if (!response.ok) {
            // Handle authentication errors
            if (response.status === 401) {
                this.handleAuthError();
                throw new Error(data.error || 'Authentication required');
            }

            throw new Error(data.error || `HTTP error! status: ${response.status}`);
        }

        return data;
    }

    /**
     * Handle API errors with retry logic
     * @param {Error} error Original error
     * @param {string} method HTTP method
     * @param {string} endpoint API endpoint
     * @param {Object} data Request data
     * @param {Object} options Request options
     * @returns {Promise} Retry result or error
     */
    async handleError(error, method, endpoint, data, options) {
        console.error('API Error:', error);

        // Check if it's a network error that can be retried
        if (error.name === 'TypeError' || error.name === 'AbortError') {
            const retryCount = options.retryCount || 0;
            if (retryCount < this.retryAttempts) {
                console.log(`Retrying request... Attempt ${retryCount + 1}`);
                await this.delay(1000 * (retryCount + 1)); // Exponential backoff
                return this.request(method, endpoint, data, { 
                    ...options, 
                    retryCount: retryCount + 1 
                });
            }
        }

        throw error;
    }

    /**
     * Handle authentication errors
     */
    handleAuthError() {
        this.isAuthenticated = false;
        this.currentUser = null;
        
        // Clear any stored session data
        sessionStorage.clear();
        
        // Redirect to login if not already there
        if (!window.location.pathname.includes('login')) {
            this.showToast('Session expired. Please log in again.', 'error');
            // Don't redirect immediately in API helper - let the app handle it
        }
    }

    /**
     * Delay helper for retry logic
     * @param {number} ms Milliseconds to delay
     * @returns {Promise} Promise that resolves after delay
     */
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    // Authentication methods
    async login(email, password) {
        const response = await this.request('POST', 'auth.php', { email, password });
        if (response.success) {
            this.isAuthenticated = true;
            this.currentUser = response.data;
            sessionStorage.setItem('user', JSON.stringify(response.data));
        }
        return response;
    }

    async logout() {
        try {
            await this.request('DELETE', 'auth.php');
        } finally {
            this.isAuthenticated = false;
            this.currentUser = null;
            sessionStorage.clear();
        }
    }

    async getCurrentUser() {
        if (!this.isAuthenticated) {
            const storedUser = sessionStorage.getItem('user');
            if (storedUser) {
                this.currentUser = JSON.parse(storedUser);
                this.isAuthenticated = true;
            }
        }

        if (this.isAuthenticated) {
            try {
                const response = await this.request('GET', 'auth.php');
                if (response.success) {
                    this.currentUser = response.data;
                    return response.data;
                }
            } catch (error) {
                console.error('Failed to verify user session:', error);
                this.handleAuthError();
            }
        }
        return null;
    }

    // Department methods
    async getDepartments() {
        const response = await this.request('GET', 'departments.php');
        return response.data;
    }

    async getAssetTypes(departmentId) {
        const response = await this.request('GET', 'departments.php', { 
            id: departmentId, 
            asset_types: 1 
        });
        return response.data;
    }

    async getDocumentTypes(assetTypeId) {
        const response = await this.request('GET', 'departments.php', { 
            asset_type_id: assetTypeId, 
            document_types: 1 
        });
        return response.data;
    }

    // Checklist methods
    async getChecklists(filters = {}) {
        const response = await this.request('GET', 'checklists.php', filters);
        return response.data;
    }

    async getChecklistById(checklistId) {
        const response = await this.request('GET', 'checklists.php', { id: checklistId });
        return response.data;
    }

    async getAssetChecklists(assetId, documentType) {
        const response = await this.request('GET', 'checklists.php', { 
            asset_id: assetId, 
            type: documentType 
        });
        return response.data;
    }

    async createChecklist(checklistData) {
        const response = await this.request('POST', 'checklists.php', checklistData);
        return response;
    }

    async updateChecklistItem(checklistId, itemId, updateData) {
        const response = await this.request('PUT', 
            `checklists.php?id=${checklistId}&item_id=${itemId}`, 
            updateData
        );
        return response;
    }

    // File upload methods
    async uploadFile(file, entityType, entityId) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('entity_type', entityType);
        formData.append('entity_id', entityId);

        const response = await this.request('POST', 'uploads.php', formData);
        return response;
    }

    async downloadFile(filename) {
        const url = `${API_CONFIG.baseURL}uploads.php?file=${encodeURIComponent(filename)}`;
        window.open(url, '_blank');
    }

    async deleteFile(attachmentId) {
        const response = await this.request('DELETE', `uploads.php?id=${attachmentId}`);
        return response;
    }

    // Utility methods
    showToast(message, type = 'info', duration = 5000) {
        const toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');

        toastContainer.appendChild(toast);

        // Auto-remove toast after duration
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, duration);

        // Allow manual removal by clicking
        toast.addEventListener('click', () => {
            toast.remove();
        });
    }

    /**
     * Format date for display
     * @param {string} dateString ISO date string
     * @returns {string} Formatted date
     */
    formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-IN', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    /**
     * Format relative time (e.g., "2 hours ago")
     * @param {string} dateString ISO date string
     * @returns {string} Relative time string
     */
    formatRelativeTime(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        const now = new Date();
        const diff = now - date;

        const seconds = Math.floor(diff / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);

        if (days > 0) return `${days} day${days > 1 ? 's' : ''} ago`;
        if (hours > 0) return `${hours} hour${hours > 1 ? 's' : ''} ago`;
        if (minutes > 0) return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
        return 'Just now';
    }

    /**
     * Validate file before upload
     * @param {File} file File object
     * @returns {Object} Validation result
     */
    validateFile(file) {
        const maxSize = 5 * 1024 * 1024; // 5MB
        const allowedTypes = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
            'application/pdf', 'text/plain', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];

        if (file.size > maxSize) {
            return { valid: false, error: 'File size must be less than 5MB' };
        }

        if (!allowedTypes.includes(file.type)) {
            return { valid: false, error: 'File type not allowed' };
        }

        return { valid: true };
    }

    /**
     * Get status color class based on status
     * @param {string} status Status string
     * @returns {string} CSS class name
     */
    getStatusClass(status) {
        const statusClasses = {
            'completed': 'success',
            'signed_off': 'success',
            'in_progress': 'warning',
            'draft': 'info',
            'expired': 'danger',
            'open': 'danger',
            'closed': 'success',
            'verified': 'success'
        };
        return statusClasses[status] || 'info';
    }

    /**
     * Sanitize HTML to prevent XSS
     * @param {string} str HTML string to sanitize
     * @returns {string} Sanitized string
     */
    sanitizeHTML(str) {
        const temp = document.createElement('div');
        temp.textContent = str;
        return temp.innerHTML;
    }

    /**
     * Debounce function calls
     * @param {Function} func Function to debounce
     * @param {number} wait Wait time in milliseconds
     * @returns {Function} Debounced function
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
}

// Create global API instance
const api = new APIHelper();

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { APIHelper, api };
} else if (typeof window !== 'undefined') {
    window.api = api;
}
