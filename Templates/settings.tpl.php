<?php
/** @var \Leantime\Core\UI\Template $tpl */
?>
<div class="pageheader">
    <div class="pageicon"><span class="fa fa-code-branch"></span></div>
    <div class="pagetitle">
        <h1>ClickUp listener plugin settings</h1>
    </div>
</div>

<div class="maincontent">
    <div class="maincontentinner">

        <?php $configs = $tpl->get('clickupConfigs') ?? []; ?>

        <div style="margin-bottom: 20px;">
            <a class="btn" href="/plugins/myapps"><span class="fa fa-arrow-left"></span></a>
        </div>
        <h3 class="widgettitle title-light">Saved webhook configurations</h3>

        <?php if (count($configs) === 0): ?>
            <div class="well">No configurations saved yet.</div>
        <?php else: ?>
            <table class="table table-striped table-responsive" id="clickup-config-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Webhook ID</th>
                        <th>Project ID</th>
                        <th>Tag</th>
                        <th>Webhook secret (masked)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($configs as $c):
                        $id = htmlspecialchars($c['id'] ?? '');
                        $webhookId = htmlspecialchars($c['webhook_id'] ?? '');
                        $projectId = htmlspecialchars($c['project_id'] ?? '');
                        $tag = htmlspecialchars($c['task_tag'] ?? '');
                        $secretMasked = htmlspecialchars(substr($c['hook_secret'] ?? '', 0, 6));
                    ?>
                        <tr id="clickup-row-<?php echo $id; ?>">
                            <td><?php echo $id; ?></td>
                            <td><?php echo $webhookId; ?></td>
                            <td>
                                <div class="clickup-project-field">
                                    <input type="number" id="project-input-<?php echo $id; ?>" class="form-control" value="<?php echo $projectId; ?>" />
                                    <span class="clickup-project-name" id="project-name-<?php echo $id; ?>" data-loaded="0"></span>
                                </div>
                            </td>
                            <td>
                                <input type="text" id="tag-input-<?php echo $id; ?>" class="form-control" value="<?php echo $tag; ?>" />
                            </td>
                            <td><code><?php echo $secretMasked; ?>…</code></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-success clickup-update-btn" data-id="<?php echo $id; ?>">Update</button>
                                <button type="button" class="btn btn-sm btn-danger clickup-delete-btn" data-id="<?php echo $id; ?>">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <hr />

        <h3 class="widgettitle title-light">Add webhook configuration</h3>

        <div class="well" style="margin-bottom: 16px;">
            <strong>How to set up the integration</strong>
            <ol style="margin-top: 8px;">
                <li>Create a ClickUp webhook for your space/folder/list and copy its Webhook ID.</li>
                <li>Optional: set a webhook secret in ClickUp and paste it here to enable signature verification.</li>
                <li>Enter the Leantime project ID you want to sync into and confirm the project name shown.</li>
                <li>Optional: add a tag that will be applied to created tickets.</li>
                <li>Save the configuration, then send a test webhook from ClickUp.</li>
            </ol>
        </div>

        <form method="post" action="/ClickupListener/settings" class="row-fluid" id="clickup-config-form">
            <div class="form-group">
                <label class="control-label" for="webhook-id">Webhook ID</label>
                <input type="text" name="webhook_id" id="webhook-id" class="form-control" placeholder="ClickUp webhook id" />
            </div>

            <div class="form-group">
                <label class="control-label" for="hook-secret">Webhook secret</label>
                <input type="text" name="hook_secret" id="hook-secret" class="form-control" placeholder="Optional secret for signature verification" />
            </div>

            <div class="form-group">
                <label class="control-label" for="project-id">Leantime project ID</label>
                <div class="clickup-project-field">
                    <input type="number" name="project_id" id="project-id" class="form-control" placeholder="e.g. 12" required />
                    <span class="clickup-project-name" id="project-name-new" data-loaded="0"></span>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label" for="task-tag">Task tag</label>
                <input type="text" name="task_tag" id="task-tag" class="form-control" placeholder="Optional tag, e.g. clickup" />
            </div>

            <div class="form-group" style="margin-top: 10px;">
                <button type="button" id="test-project" class="btn">Test project</button>
                <span id="test-spinner" style="display:none; margin-left:8px;"><i class="fa fa-spinner fa-spin"></i></span>
                <button type="submit" class="btn btn-success">Save</button>
            </div>
        </form>

        <div id="test-result" style="margin-top:12px;"></div>

    </div>
</div>

<script>
(function(){
    const btn = document.getElementById('test-project');
    const spinner = document.getElementById('test-spinner');
    const resultDiv = document.getElementById('test-result');
    const nameCache = new Map();

    function show(message, success) {
        resultDiv.innerHTML = '';
        const el = document.createElement('div');
        el.className = success ? 'alert alert-success' : 'alert alert-danger';
        el.textContent = message;
        resultDiv.appendChild(el);
    }

    async function testConn() {
        const projectId = document.getElementById('project-id').value.trim();

        if (!projectId) { show('Project ID is required', false); return; }

        // disable and show spinner
        btn.disabled = true;
        spinner.style.display = 'inline-block';
        resultDiv.innerHTML = '';

        try {
            const body = new URLSearchParams();
            body.append('project_id', projectId);

            const resp = await fetch('/ClickupListener/settings/test', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });

            const json = await resp.json().catch(() => null);
            if (resp.ok && json && json.success) {
                show(json.message || 'Connection successful', true);
            } else if (json && json.message) {
                show(json.message, false);
            } else {
                show('Connection failed (HTTP ' + resp.status + ')', false);
            }
        } catch (err) {
            show('Request failed: ' + (err.message || err), false);
        } finally {
            btn.disabled = false;
            spinner.style.display = 'none';
        }
    }

    if (btn) btn.addEventListener('click', testConn);

    async function fetchProjectName(projectId) {
        if (!projectId) return null;
        const cached = nameCache.get(projectId);
        if (cached) return cached;
        const body = new URLSearchParams();
        body.append('project_id', projectId);
        try {
            const resp = await fetch('/ClickupListener/settings/projectName', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const json = await resp.json().catch(() => null);
            if (resp.ok && json && json.success && json.name) {
                nameCache.set(projectId, json.name);
                return json.name;
            }
        } catch (err) {
            return null;
        }
        return null;
    }

    async function updateProjectName(inputEl, targetEl) {
        if (!inputEl || !targetEl) return;
        const projectId = inputEl.value.trim();
        if (projectId === '') {
            targetEl.textContent = '';
            targetEl.dataset.loaded = '0';
            return;
        }
        if (targetEl.dataset.loaded === '1' && targetEl.dataset.projectId === projectId) {
            return;
        }
        targetEl.textContent = 'Loading...';
        targetEl.dataset.loaded = '1';
        targetEl.dataset.projectId = projectId;
        const name = await fetchProjectName(projectId);
        if (name) {
            targetEl.textContent = name;
        } else {
            targetEl.textContent = 'Project not found';
        }
    }

    function bindProjectField(inputId, nameId) {
        const inputEl = document.getElementById(inputId);
        const nameEl = document.getElementById(nameId);
        if (!inputEl || !nameEl) return;
        inputEl.addEventListener('change', () => updateProjectName(inputEl, nameEl));
        inputEl.addEventListener('blur', () => updateProjectName(inputEl, nameEl));
        if (inputEl.value.trim() !== '') {
            updateProjectName(inputEl, nameEl);
        }
    }

    bindProjectField('project-id', 'project-name-new');
    document.querySelectorAll('[id^="project-input-"]').forEach(inputEl => {
        const id = inputEl.id.replace('project-input-', '');
        bindProjectField(inputEl.id, 'project-name-' + id);
    });

    // Update and Delete handlers for rows
    function showRowMessage(rowId, message, success) {
        const row = document.getElementById('clickup-row-' + rowId);
        if (!row) return;
        let container = row.querySelector('.row-msg');
        if (!container) {
            container = document.createElement('div');
            container.className = 'row-msg';
            container.style.marginTop = '8px';
            row.cells[row.cells.length - 1].appendChild(container);
        }
        container.innerHTML = '';
        const el = document.createElement('div');
        el.className = success ? 'alert alert-success' : 'alert alert-danger';
        el.textContent = message;
        container.appendChild(el);
    }

    async function updateRow(id) {
        const projectInput = document.getElementById('project-input-' + id);
        const tagInput = document.getElementById('tag-input-' + id);
        if (!projectInput || !tagInput) return;
        const projectId = projectInput.value.trim();
        const tag = tagInput.value.trim();
        if (projectId === '') { showRowMessage(id, 'Project ID is required', false); return; }

        const body = new URLSearchParams();
        body.append('id', id);
        body.append('project_id', projectId);
        body.append('task_tag', tag);

        const updateBtn = document.querySelector('.clickup-update-btn[data-id="' + id + '"]');
        updateBtn.disabled = true;

        try {
            const resp = await fetch('/ClickupListener/settings/update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const json = await resp.json().catch(() => null);
            if (resp.ok && json && json.success) {
                showRowMessage(id, json.message || 'Updated', true);
            } else if (json && json.message) {
                showRowMessage(id, json.message, false);
            } else {
                showRowMessage(id, 'Update failed (HTTP ' + resp.status + ')', false);
            }
        } catch (err) {
            showRowMessage(id, 'Request failed: ' + (err.message || err), false);
        } finally {
            updateBtn.disabled = false;
        }
    }

    async function deleteRow(id) {
        if (!confirm('Delete configuration #' + id + '?')) return;

        const body = new URLSearchParams();
        body.append('id', id);

        const deleteBtn = document.querySelector('.clickup-delete-btn[data-id="' + id + '"]');
        deleteBtn.disabled = true;

        try {
            const resp = await fetch('/ClickupListener/settings/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const json = await resp.json().catch(() => null);
            if (resp.ok && json && json.success) {
                // remove row from table
                const row = document.getElementById('clickup-row-' + id);
                if (row) row.parentNode.removeChild(row);
            } else if (json && json.message) {
                showRowMessage(id, json.message, false);
                deleteBtn.disabled = false;
            } else {
                showRowMessage(id, 'Delete failed (HTTP ' + resp.status + ')', false);
                deleteBtn.disabled = false;
            }
        } catch (err) {
            showRowMessage(id, 'Request failed: ' + (err.message || err), false);
            deleteBtn.disabled = false;
        }
    }

    // wire up existing buttons
    document.querySelectorAll('.clickup-update-btn').forEach(b => {
        b.addEventListener('click', function(){ updateRow(this.getAttribute('data-id')); });
    });
    document.querySelectorAll('.clickup-delete-btn').forEach(b => {
        b.addEventListener('click', function(){ deleteRow(this.getAttribute('data-id')); });
    });

})();
</script>

<style>
    .clickup-project-field {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .clickup-project-name {
        font-size: 12px;
        color: #666;
        white-space: nowrap;
    }
</style>
