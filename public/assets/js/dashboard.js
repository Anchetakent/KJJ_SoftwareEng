function togglePane(paneId) {
    const panes = ['add-student-pane', 'edit-student-pane', 'edit-class-pane', 'add-category-pane', 'ass-form-pane'];
    panes.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.style.display = (id === paneId && el.style.display !== 'block') ? 'block' : 'none';
        }
    });
}

function openAddPane() { togglePane('add-student-pane'); }

function openEditStudent(id, name) {
    togglePane('edit-student-pane');
    document.getElementById('edit-old-id').value = id;
    document.getElementById('edit-new-id').value = id;
    document.getElementById('edit-new-name').value = name;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function openAddAssignment(catId, catName) {
    togglePane('ass-form-pane');
    document.getElementById('ass-form-title').innerText = 'Add Assignment to: ' + catName;
    document.getElementById('ass-cat-id').value = catId;
    document.getElementById('ass-id').value = '';
    document.getElementById('ass-name').value = '';
    document.getElementById('ass-max').value = '';
    document.getElementById('ass-submit-add').style.display = 'block';
    document.getElementById('ass-submit-edit').style.display = 'none';
}

function openEditAssignment(assId, assName, maxScore) {
    togglePane('ass-form-pane');
    document.getElementById('ass-form-title').innerText = 'Edit Assignment';
    document.getElementById('ass-cat-id').value = '';
    document.getElementById('ass-id').value = assId;
    document.getElementById('ass-name').value = assName;
    document.getElementById('ass-max').value = maxScore;
    document.getElementById('ass-submit-add').style.display = 'none';
    document.getElementById('ass-submit-edit').style.display = 'block';
}

function filterGradebookStudents(query) {
    const search = query.trim().toLowerCase();
    const rows = document.querySelectorAll('.gradebook-row');
    const countEl = document.getElementById('gradebookSearchCount');
    let visibleCount = 0;

    rows.forEach((row) => {
        const studentId = (row.dataset.studentId || '').toLowerCase();
        const studentName = (row.dataset.studentName || '').toLowerCase();
        const matches = !search || studentId.includes(search) || studentName.includes(search);
        row.style.display = matches ? '' : 'none';
        if (matches) visibleCount++;
    });

    if (countEl) {
        countEl.textContent = search ? `Showing ${visibleCount} student${visibleCount === 1 ? '' : 's'}` : 'Showing all students';
    }
}

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

(function () {
    const inputs = document.querySelectorAll('.gradebook-score-input');
    const statusEl = document.getElementById('gradebookSaveStatus');

    function setStatus(text, tone) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.style.color = tone === 'saving' ? '#0284c7' : tone === 'saved' ? 'var(--primary)' : tone === 'error' ? '#b91c1c' : 'var(--text-muted)';
    }

    function recalculateBucketTotal(row) {
        const bucketCell = row.querySelector('td:last-child');
        if (!bucketCell) return;

        const gradeInputs = row.querySelectorAll('.gradebook-score-input');
        const categories = {};
        let total = 0;

        gradeInputs.forEach(input => {
            const maxScore = parseFloat(input.dataset.maxScore);
            if (isNaN(maxScore) || maxScore <= 0) return;

            const score = parseFloat(input.value) || 0;
            const categoryId = input.dataset.categoryId;
            const weight = parseFloat(input.dataset.categoryWeight);

            if (!categories[categoryId]) {
                categories[categoryId] = { earned: 0, max: 0, weight: weight };
            }
            categories[categoryId].earned += score;
            categories[categoryId].max += maxScore;
        });

        Object.values(categories).forEach(cat => {
            if (cat.max > 0) total += (cat.earned / cat.max) * cat.weight;
        });

        bucketCell.textContent = total.toFixed(2) + '%';
    }

    async function saveScore(input) {
        const maxScore = parseFloat(input.dataset.maxScore);
        const score = parseFloat(input.value) || 0;

        if (!isNaN(maxScore) && score > maxScore) {
            input.style.borderColor = '#dc2626';
            input.style.backgroundColor = '#fee2e2';
            setStatus(`Max score is ${maxScore}`, 'error');
            return;
        }

        input.style.borderColor = '';
        input.style.backgroundColor = '';

        const studentInternalId = input.dataset.studentInternalId;
        const assignmentId = input.dataset.assignmentId;

        setStatus('Saving...', 'saving');

        const formData = new FormData();
        formData.append('autosave_score', '1');
        formData.append('student_internal_id', studentInternalId);
        formData.append('assignment_id', assignmentId);
        formData.append('score', score);
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error('Autosave failed');

            const row = input.closest('tr');
            if (row) recalculateBucketTotal(row);
            setStatus('Saved', 'saved');
        } catch (error) {
            setStatus('Save failed', 'error');
        }
    }

    inputs.forEach((input) => {
        input.addEventListener('input', () => saveScore(input));
        input.addEventListener('change', () => saveScore(input));
    });
})();

const ResponsiveManager = {
    isMobile() { return window.innerWidth <= 768; },
    isTablet() { return window.innerWidth > 768 && window.innerWidth <= 1024; },
    isDesktop() { return window.innerWidth > 1024; },
    onResize(callback) {
        window.addEventListener('resize', callback);
        callback();
    }
};

const BottomSheetManager = {
    sheets: {},
    register(id, sheet) { this.sheets[id] = { element: sheet, touchStart: 0, touchStartY: 0 }; },
    open(id) {
        if (!this.sheets[id]) return;
        const { element } = this.sheets[id];
        element.classList.add('open');
        this.showOverlay();
        document.body.style.overflow = 'hidden';
    },
    close(id) {
        if (!this.sheets[id]) return;
        const { element } = this.sheets[id];
        element.classList.remove('open');
        this.hideOverlay();
        document.body.style.overflow = 'auto';
    },
    showOverlay() {
        const overlay = document.querySelector('.bottom-sheet-overlay');
        if (overlay) overlay.classList.add('open');
    },
    hideOverlay() {
        const overlay = document.querySelector('.bottom-sheet-overlay');
        if (overlay) overlay.classList.remove('open');
    },
    initGestureHandling(id) {
        const sheet = this.sheets[id]?.element;
        if (!sheet) return;
        sheet.addEventListener('touchstart', (e) => {
            this.sheets[id].touchStartY = e.touches[0].clientY;
        });
        sheet.addEventListener('touchend', (e) => {
            const touchEndY = e.changedTouches[0].clientY;
            const diff = touchEndY - this.sheets[id].touchStartY;
            if (diff > 50) this.close(id);
        });
    }
};

const FeedbackToast = {
    show(message, type = 'success', duration = 3000) {
        const toast = document.createElement('div');
        toast.className = `feedback-toast feedback-${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), duration);
    }
};

const GradeEditModal = {
    modal: null,
    currentInput: null,
    currentData: null,
    init() {
        this.modal = document.getElementById('gradeEditModal');
        if (this.modal) {
            BottomSheetManager.register('gradeEditModal', this.modal);
            BottomSheetManager.initGestureHandling('gradeEditModal');
        }
    },
    open(studentName, studentId, assignmentName, maxScore, currentValue, inputElement) {
        if (!this.modal) this.init();
        this.currentInput = inputElement;
        this.currentData = { maxScore: parseFloat(maxScore) };
        document.getElementById('modalStudentName').textContent = studentName;
        document.getElementById('modalStudentId').textContent = studentId;
        document.getElementById('modalAssignmentName').textContent = assignmentName;
        document.getElementById('modalMaxScore').textContent = maxScore;
        document.getElementById('modalAssignmentContext').textContent = `Max Score: ${maxScore}`;
        document.getElementById('modalErrorMessage').style.display = 'none';
        document.getElementById('modalErrorMessage').textContent = '';
        const gradeInput = document.getElementById('modalGradeInput');
        gradeInput.value = currentValue || '';
        gradeInput.focus();
        BottomSheetManager.open('gradeEditModal');
    },
    close() {
        if (this.modal) BottomSheetManager.close('gradeEditModal');
        this.currentInput = null;
        this.currentData = null;
    },
    validate() {
        const value = document.getElementById('modalGradeInput').value.trim();
        const errorMsg = document.getElementById('modalErrorMessage');
        const maxScore = this.currentData.maxScore;
        errorMsg.style.display = 'none';
        errorMsg.textContent = '';
        if (value === '') return true;
        const score = parseFloat(value);
        if (isNaN(score)) {
            errorMsg.textContent = 'Please enter a valid number';
            errorMsg.style.display = 'block';
            return false;
        }
        if (score < 0) {
            errorMsg.textContent = 'Score cannot be negative';
            errorMsg.style.display = 'block';
            return false;
        }
        if (score > maxScore) {
            errorMsg.textContent = `Score cannot exceed ${maxScore}`;
            errorMsg.style.display = 'block';
            return false;
        }
        return true;
    },
    async save() {
        if (!this.validate()) return;
        const value = document.getElementById('modalGradeInput').value.trim();
        const btn = document.getElementById('modalSaveBtn');
        btn.disabled = true;
        btn.textContent = 'Saving...';
        try {
            const formData = new FormData();
            formData.append('autosave_score', '1');
            formData.append('student_internal_id', this.currentInput.getAttribute('data-student-internal-id'));
            formData.append('assignment_id', this.currentInput.getAttribute('data-assignment-id'));
            formData.append('score', value);
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                this.currentInput.value = value;
                this.currentInput.dispatchEvent(new Event('change', { bubbles: true }));
                FeedbackToast.show('✓ Score saved successfully', 'success', 2000);
                this.close();
            } else {
                document.getElementById('modalErrorMessage').textContent = 'Failed to save. Please try again.';
                document.getElementById('modalErrorMessage').style.display = 'block';
            }
        } catch (error) {
            document.getElementById('modalErrorMessage').textContent = 'Network error. Please check your connection.';
            document.getElementById('modalErrorMessage').style.display = 'block';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Save Score';
        }
    }
};

const EmptyStateBuilder = {
    create(icon, title, text, actionLabel = null, actionCallback = null) {
        const container = document.createElement('div');
        container.className = 'empty-state';
        const actionHTML = actionLabel && actionCallback ? `
            <div class="empty-state-action">
                <button onclick="EmptyStateBuilder._triggerAction(event)">${actionLabel}</button>
            </div>
        ` : '';
        container.innerHTML = `
            <div class="empty-state-icon">${icon}</div>
            <div class="empty-state-title">${title}</div>
            <div class="empty-state-text">${text}</div>
            ${actionHTML}
        `;
        if (actionCallback) container._actionCallback = actionCallback;
        return container;
    },
    _triggerAction(event) {
        const container = event.target.closest('.empty-state');
        if (container && container._actionCallback) container._actionCallback();
    },
    noStudents() { return this.create('[USERS]', 'No Students Yet', 'Add students to this section to view and manage their grades'); },
    noGrades() { return this.create('[DOCUMENT]', 'No Grades Entered', 'Create assignments and start entering grades for your students'); },
    noSearchResults(query) { return this.create('[SEARCH]', 'No Results Found', `No students match "${query}". Try a different name or ID`); }
};

const MobileGradebookRenderer = {
    tableSelector: '.gradebook-table',
    cardContainerId: 'mobilegradebookCards',
    allStudents: [],
    extractStudentData() {
        const table = document.querySelector(this.tableSelector);
        if (!table) return [];
        const students = [];
        const rows = table.querySelectorAll('tbody tr.gradebook-row');
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            const inputs = row.querySelectorAll('.gradebook-score-input');
            const student = {
                name: cells[0]?.textContent.trim() || 'Unknown',
                id: row.getAttribute('data-student-id') || '',
                internalId: inputs[0]?.getAttribute('data-student-internal-id') || '',
                total: cells[cells.length - 1]?.textContent.trim() || '0%',
                assignments: []
            };
            inputs.forEach(input => {
                const assignmentName = input.closest('td').previousElementSibling?.innerHTML || '';
                student.assignments.push({
                    id: input.getAttribute('data-assignment-id'),
                    name: assignmentName.split('<br>')[0] || 'Assignment',
                    score: input.value || '—',
                    max: input.getAttribute('data-max-score'),
                    categoryWeight: input.getAttribute('data-category-weight')
                });
            });
            students.push(student);
        });
        this.allStudents = students;
        return students;
    },
    renderCard(student) {
        const card = document.createElement('div');
        card.className = 'student-grade-card';
        card.setAttribute('data-student-internal-id', student.internalId);
        const assignmentsHTML = student.assignments.map(a => `
            <div class="assignment-row" data-assignment-id="${a.id}">
                <div style="flex: 1;">
                    <div class="assignment-name">${a.name}</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">Weight: ${a.categoryWeight}%</div>
                </div>
                <div class="assignment-score">${a.score}/${a.max}</div>
                <button type="button" class="edit-grade-btn" data-assignment-id="${a.id}" data-student-id="${student.internalId}" onclick="MobileGradebookRenderer.openEditModal(event)">Edit</button>
            </div>
        `).join('');
        card.innerHTML = `
            <div class="student-header">
                <div><div class="student-name">${student.name}</div><div class="student-id">${student.id}</div></div>
                <div class="bucket-total-badge" id="total-${student.internalId}">${student.total}</div>
            </div>
            <div class="assignments-list">${assignmentsHTML}</div>
        `;
        return card;
    },
    renderAll(searchQuery = '') {
        const container = document.getElementById(this.cardContainerId);
        if (!container) return;
        let students = this.allStudents;
        if (searchQuery) {
            const query = searchQuery.toLowerCase();
            students = students.filter(s => s.name.toLowerCase().includes(query) || s.id.toLowerCase().includes(query));
            if (students.length === 0) {
                container.innerHTML = '';
                container.appendChild(EmptyStateBuilder.noSearchResults(searchQuery));
                return;
            }
        }
        container.innerHTML = '';
        if (students.length === 0) container.appendChild(EmptyStateBuilder.noStudents());
        else students.forEach(student => container.appendChild(this.renderCard(student)));
    },
    openEditModal(event) {
        event.preventDefault();
        const btn = event.target;
        const assignmentId = btn.getAttribute('data-assignment-id');
        const studentId = btn.getAttribute('data-student-id');
        const input = document.querySelector(`input[data-student-internal-id="${studentId}"][data-assignment-id="${assignmentId}"]`);
        if (input && ResponsiveManager.isMobile()) {
            const studentName = input.closest('.student-grade-card')?.querySelector('.student-name')?.textContent || 'Student';
            const studentIdText = input.closest('.student-grade-card')?.querySelector('.student-id')?.textContent || '';
            const assignmentName = input.closest('.assignment-row')?.querySelector('.assignment-name')?.textContent || 'Assignment';
            const maxScore = input.getAttribute('data-max-score');
            const currentValue = input.value;
            GradeEditModal.open(studentName, studentIdText, assignmentName, maxScore, currentValue, input);
        }
    },
    updateCardTotals() {
        const table = document.querySelector(this.tableSelector);
        if (!table) return;
        const rows = table.querySelectorAll('tbody tr.gradebook-row');
        rows.forEach(row => {
            const studentId = row.getAttribute('data-student-internal-id') || row.querySelector('.gradebook-score-input')?.getAttribute('data-student-internal-id');
            const totalCell = row.querySelector('td:last-child');
            const total = totalCell?.textContent.trim() || '0%';
            const badge = document.getElementById(`total-${studentId}`);
            if (badge) badge.textContent = total;
        });
    }
};

document.addEventListener('click', (e) => {
    if (e.target.classList.contains('edit-grade-btn') && ResponsiveManager.isMobile()) {
        e.preventDefault();
        const assignmentId = e.target.getAttribute('data-assignment-id');
        const studentInternalId = e.target.getAttribute('data-student-id');
        const input = document.querySelector(`input[data-student-internal-id="${studentInternalId}"][data-assignment-id="${assignmentId}"]`);
        if (input) {
            const studentName = input.closest('.student-grade-card')?.querySelector('.student-name')?.textContent || 'Student';
            const studentId = input.closest('.student-grade-card')?.querySelector('.student-id')?.textContent || '';
            const assignmentName = input.closest('.assignment-row')?.querySelector('.assignment-name')?.textContent || 'Assignment';
            const maxScore = input.getAttribute('data-max-score');
            const currentValue = input.value;
            GradeEditModal.open(studentName, studentId, assignmentName, maxScore, currentValue, input);
        }
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const hamburgerMenu = document.getElementById('hamburgerMenu');
    const sidebar = document.querySelector('.sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (hamburgerMenu && sidebar && sidebarOverlay) {
        hamburgerMenu.addEventListener('click', function () {
            sidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('open');
        });
        sidebarOverlay.addEventListener('click', function () {
            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('open');
        });
        const sidebarLinks = sidebar.querySelectorAll('a');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function () {
                sidebar.classList.remove('open');
                sidebarOverlay.classList.remove('open');
            });
        });
    }

    const openDeleteClassModalBtn = document.getElementById('openDeleteClassModalBtn');
    const deleteClassModal = document.getElementById('deleteClassModal');
    const cancelDeleteClassBtn = document.getElementById('cancelDeleteClassBtn');
    const confirmDeleteClassBtn = document.getElementById('confirmDeleteClassBtn');
    const deleteClassForm = document.getElementById('deleteClassForm');

    if (openDeleteClassModalBtn && deleteClassModal) {
        openDeleteClassModalBtn.addEventListener('click', () => {
            deleteClassModal.style.display = 'flex';
        });
    }

    if (cancelDeleteClassBtn && deleteClassModal) {
        cancelDeleteClassBtn.addEventListener('click', () => {
            deleteClassModal.style.display = 'none';
        });
    }

    if (confirmDeleteClassBtn && deleteClassForm) {
        confirmDeleteClassBtn.addEventListener('click', () => {
            deleteClassForm.submit();
        });
    }

    const overlay = document.querySelector('.bottom-sheet-overlay');
    if (overlay) {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                const sheet = document.querySelector('.bottom-sheet.open');
                if (sheet) {
                    Object.keys(BottomSheetManager.sheets).forEach(id => {
                        if (BottomSheetManager.sheets[id].element === sheet) BottomSheetManager.close(id);
                    });
                }
            }
        });
    }

    GradeEditModal.init();
    MobileGradebookRenderer.extractStudentData();
    MobileGradebookRenderer.renderAll();

    const searchInput = document.getElementById('studentGradeSearch');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value;
            const table = document.querySelector(MobileGradebookRenderer.tableSelector);
            if (table) {
                const rows = table.querySelectorAll('tbody tr.gradebook-row');
                rows.forEach(row => {
                    const studentId = row.getAttribute('data-student-id') || '';
                    const studentName = row.getAttribute('data-student-name') || '';
                    const matches = studentId.toLowerCase().includes(query.toLowerCase()) || studentName.toLowerCase().includes(query.toLowerCase());
                    row.style.display = matches ? '' : 'none';
                });
            }
            MobileGradebookRenderer.renderAll(query);
            const count = document.querySelector('#gradebookSearchCount');
            if (count) {
                if (query) {
                    const visibleCount = MobileGradebookRenderer.allStudents.filter(s => s.name.toLowerCase().includes(query.toLowerCase()) || s.id.toLowerCase().includes(query.toLowerCase())).length;
                    count.textContent = `Showing ${visibleCount} of ${MobileGradebookRenderer.allStudents.length} students`;
                } else count.textContent = 'Showing all students';
            }
        });
    }

    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            MobileGradebookRenderer.extractStudentData();
            MobileGradebookRenderer.renderAll();
        }, 300);
    });

    document.addEventListener('change', (e) => {
        if (e.target.classList.contains('gradebook-score-input')) {
            setTimeout(() => {
                MobileGradebookRenderer.updateCardTotals();
                MobileGradebookRenderer.extractStudentData();
            }, 500);
        }
    });

    const analytics = window.dashboardAnalyticsData;
    const distributionCanvas = document.getElementById('overallDistributionChart');
    const passFailCanvas = document.getElementById('overallPassFailChart');
    if (analytics && distributionCanvas && passFailCanvas && typeof Chart !== 'undefined') {
        const distributionData = analytics.distributionData || {};
        const passingCount = analytics.passingCount || 0;
        const failingCount = Math.max(0, (analytics.totalCount || 0) - passingCount);
        new Chart(distributionCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: Object.keys(distributionData),
                datasets: [{
                    label: 'Students',
                    data: Object.values(distributionData),
                    backgroundColor: ['#ef4444', '#f97316', '#f59e0b', '#22c55e', '#16a34a'],
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
        new Chart(passFailCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Passing', 'Failing'],
                datasets: [{
                    data: [passingCount, failingCount],
                    backgroundColor: ['#10b981', '#ef4444'],
                    borderColor: ['#ffffff', '#ffffff'],
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    const tables = document.querySelectorAll('.analytics-table tbody');
    tables.forEach(tbody => {
        if (tbody.children.length === 0) {
            tbody.parentElement.replaceWith(EmptyStateBuilder.noGrades());
        }
    });

    ResponsiveManager.onResize(() => {
        console.log('Device:', ResponsiveManager.isMobile() ? 'Mobile' : ResponsiveManager.isTablet() ? 'Tablet' : 'Desktop');
    });
});
