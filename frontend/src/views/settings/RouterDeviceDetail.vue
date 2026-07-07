<template>
  <div class="page-container" v-loading="loading">
    <div class="page-header" v-if="device">
      <div>
        <h2>{{ device.device_no || device.hostname || device.serial_number }}</h2>
        <p class="text-muted">{{ device.serial_number }} · <el-tag :type="statusType(device.status)" size="small">{{ statusLabel(device.status) }}</el-tag></p>
      </div>
      <div class="header-actions">
        <el-button v-if="device.status !== 'decommissioned'" @click="handleInstallToken">
          {{ ['inventory','provisioned'].includes(device.status) ? '生成安装令牌' : '重装系统令牌' }}
        </el-button>
        <el-button v-if="!device.customer_id && device.status !== 'decommissioned'" type="primary" @click="bindVisible = true; searchCustomers('')">绑定客户</el-button>
        <el-button v-if="device.customer_id" @click="handleUnbind">解绑</el-button>
        <el-button v-if="device.status !== 'decommissioned'" @click="handlePushConfig">推送配置</el-button>
        <el-dropdown trigger="click">
          <el-button>更多</el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item @click="editVisible = true">编辑设备</el-dropdown-item>
              <el-dropdown-item v-if="device.status !== 'decommissioned'" @click="handleReboot">远程重启设备</el-dropdown-item>
              <el-dropdown-item v-if="device.status !== 'decommissioned'" @click="restartDialogVisible = true">重启服务</el-dropdown-item>
              <el-dropdown-item divided @click="handleDecommission" style="color: #f56c6c">停用设备</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </div>

    <el-tabs v-model="activeTab" v-if="device">
      <!-- 概览 -->
      <el-tab-pane label="概览" name="overview">
        <el-row :gutter="20">
          <el-col :span="12">
            <el-descriptions :column="1" border>
              <el-descriptions-item label="设备编号">{{ device.device_no || '-' }}</el-descriptions-item>
              <el-descriptions-item label="设备 ID">{{ device.id }}</el-descriptions-item>
              <el-descriptions-item label="序列号">{{ device.serial_number }}</el-descriptions-item>
              <el-descriptions-item label="主机名">{{ device.hostname || '-' }}</el-descriptions-item>
              <el-descriptions-item label="状态">
                <el-tag :type="statusType(device.status)" size="small">{{ statusLabel(device.status) }}</el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="客户">
                {{ device.customer?.customer_name || '未绑定' }}
              </el-descriptions-item>
              <el-descriptions-item label="模块">{{ moduleLabel(device.bound_module) || '-' }}</el-descriptions-item>
              <el-descriptions-item label="路由器型号">{{ device.router_model?.name || '-' }}</el-descriptions-item>
              <el-descriptions-item label="AP 型号">{{ device.ap_model?.name || '-' }}</el-descriptions-item>
              <el-descriptions-item label="套餐">{{ device.bundle?.name || '-' }}</el-descriptions-item>
              <el-descriptions-item label="备注">{{ device.remark || '-' }}</el-descriptions-item>
            </el-descriptions>
          </el-col>
          <el-col :span="12">
            <el-descriptions :column="1" border>
              <el-descriptions-item label="配置版本">
                v{{ device.config_version }}
                <el-tag v-if="device.config_synced" type="success" size="small" class="ml-8">已同步</el-tag>
                <el-tag v-else type="warning" size="small" class="ml-8">待同步 (applied: v{{ device.applied_config_version }})</el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="Agent 版本">{{ device.agent_version || '-' }}</el-descriptions-item>
              <el-descriptions-item label="WiFi 架构">
                <el-tag :type="device.wifi_version >= 2 ? 'success' : ''" size="small">
                  {{ device.wifi_version >= 2 ? 'v2 (Flat IP)' : 'v1 (VLAN)' }}
                </el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="每 WiFi 最大设备" v-if="device.wifi_version >= 2">
                <el-tag size="small">{{ device.wifi_max_devices_per_account || 5 }} 台</el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="管理段 DHCP" v-if="device.wifi_version >= 2">
                <el-button size="small" type="warning" @click="handleToggleTrunkDhcp(true)" :loading="trunkDhcpLoading">
                  临时开启
                </el-button>
                <el-button size="small" @click="handleToggleTrunkDhcp(false)" :loading="trunkDhcpLoading">
                  关闭
                </el-button>
                <span class="text-muted" style="margin-left: 8px; font-size: 12px">开启后可连接 AP 进行管理</span>
              </el-descriptions-item>
              <el-descriptions-item label="灰度 Agent" v-if="device.target_agent_version">
                <el-tag type="warning" size="small">{{ device.target_agent_version }}</el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="公网 IP">{{ device.wan_ip || '-' }}</el-descriptions-item>
              <el-descriptions-item label="WG IP 1">{{ device.wg_ip_1 || '-' }}</el-descriptions-item>
              <el-descriptions-item label="WG IP 2">{{ device.wg_ip_2 || '-' }}</el-descriptions-item>
              <el-descriptions-item label="最后心跳">{{ device.last_heartbeat_at ? formatTime(device.last_heartbeat_at) : '从未' }}</el-descriptions-item>
              <el-descriptions-item label="系统信息" v-if="device.system_info">
                CPU: {{ device.system_info.cpu_temp ?? '-' }}°C ·
                内存: {{ device.system_info.mem_used_mb ?? '-' }}/{{ device.system_info.mem_total_mb ?? '-' }}MB ·
                运行: {{ formatUptime(device.system_info.uptime_seconds) }}
              </el-descriptions-item>
              <el-descriptions-item label="创建时间">{{ formatTime(device.created_at) }}</el-descriptions-item>
            </el-descriptions>
          </el-col>
        </el-row>
      </el-tab-pane>

      <!-- WiFi 账号 -->
      <el-tab-pane label="WiFi 账号" name="wifi">
        <div style="margin-bottom: 12px; display: flex; gap: 8px">
          <el-button type="primary" size="small" @click="openWifiWizard()" :disabled="!device.customer_id">添加账号</el-button>
          <el-button type="warning" size="small" @click="handleCleanStale()" :loading="cleaningStale" :disabled="device.wifi_version < 2" plain>一键清理残留连接</el-button>
        </div>
        <el-table :data="paginatedWifi" v-loading="wifiLoading" stripe>
          <el-table-column prop="vlan_id" label="VLAN" width="70" v-if="device.wifi_version < 2" />
          <el-table-column prop="username" label="用户名" width="130" />
          <el-table-column prop="password" label="密码" width="130" />
          <el-table-column prop="label" label="标签" width="120" />
          <el-table-column label="IP 分配" width="200">
            <template #default="{ row }">
              <template v-if="device.wifi_version >= 2 && row.ip_start_index >= 2">
                <span style="font-family: monospace; font-size: 12px">{{ ipRange(row) }}</span>
                <el-tag size="small" type="info" style="margin-left: 4px">{{ row.max_devices }}台</el-tag>
              </template>
              <span v-else>{{ row.ip_prefix }}</span>
            </template>
          </el-table-column>
          <el-table-column label="代理模式" width="100">
            <template #default="{ row }">
              <el-tag :type="row.proxy_mode === 'proxy' ? '' : 'info'" size="small">
                {{ row.proxy_mode === 'proxy' ? '代理' : '直连' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="绑定节点" min-width="200">
            <template #default="{ row }">
              <template v-if="row.subscription">
                <span v-if="row.subscription.proxy_ip">{{ row.subscription.proxy_ip.country_name }} {{ row.subscription.proxy_ip.ip_address }}</span>
                <span v-else>订阅 #{{ row.proxy_subscription_id }}</span>
              </template>
              <span v-else class="text-muted">未绑定</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="70" align="center">
            <template #default="{ row }">
              <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="180" fixed="right">
            <template #default="{ row }">
              <el-button size="small" link @click="showWifiGuide(row)">连接信息</el-button>
              <el-button size="small" link @click="openWifiEditDialog(row)">编辑</el-button>
              <el-button size="small" link type="danger" @click="handleDeleteWifi(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div style="margin-top: 12px; display: flex; justify-content: flex-end" v-if="wifiAccounts.length > wifiPageSize">
          <el-pagination
            v-model:current-page="wifiPage"
            :page-size="wifiPageSize"
            :total="wifiAccounts.length"
            layout="total, prev, pager, next"
            small
          />
        </div>
      </el-tab-pane>

      <!-- WireGuard -->
      <el-tab-pane label="WireGuard" name="wg">
        <el-table :data="device.wg_peers || []" stripe>
          <el-table-column label="接口" width="80">
            <template #default="{ $index }">wg{{ $index }}</template>
          </el-table-column>
          <el-table-column label="服务器" width="200">
            <template #default="{ row }">{{ row.server?.name }} ({{ row.server?.endpoint }})</template>
          </el-table-column>
          <el-table-column prop="assigned_ip" label="分配 IP" width="150" />
          <el-table-column prop="peer_public_key" label="公钥" min-width="200" show-overflow-tooltip />
          <el-table-column label="状态" width="80">
            <template #default="{ row }">
              <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '活跃' : '停用' }}</el-tag>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <!-- AP 配置 -->
      <el-tab-pane label="AP 配置" name="ap">
        <el-card shadow="never">
          <template #header>
            <div style="display: flex; justify-content: space-between; align-items: center">
              <span style="font-weight: 600">OpenWrt AP 一键配置脚本</span>
              <div>
                <el-button size="small" @click="copyApScript">复制脚本</el-button>
                <el-button size="small" type="primary" @click="copyApSshCmd">复制 SSH 命令</el-button>
              </div>
            </div>
          </template>
          <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
            通过 SSH 登录 AP 后运行此脚本，自动完成三频 WiFi、WPA2-EAP 认证、NSS 硬件加速、WAN SSH 等全部配置。
            <span v-if="device?.ap_ip">
              快捷命令: <code style="background: #f0f2f5; padding: 1px 6px; border-radius: 3px; user-select: all">ssh root@{{ device.ap_ip }}</code>
            </span>
          </el-alert>
          <pre class="ap-script-block"><code>{{ apSetupScript }}</code></pre>
        </el-card>

        <el-row :gutter="24" style="margin-top: 20px">
          <el-col :span="12">
            <el-card shadow="never">
              <template #header><span style="font-weight: 600">配置说明</span></template>
              <div style="font-size: 13px; color: #606266; line-height: 1.8">
                <p><b>脚本功能 (v2 Flat IP):</b></p>
                <ul style="padding-left: 20px; margin: 4px 0 12px">
                  <li>自动检测所有射频 (2.4G / 5G / 6G)，含 PCIe 5GHz</li>
                  <li>统一配置 SSID: <code>SunIPIP.com Streaming LAN</code></li>
                  <li>NSS 硬件加速开启 (nss_offload=1)</li>
                  <li>WPA2-EAP 企业级认证 (RADIUS)</li>
                  <li>Flat IP 模式 (dynamic_vlan=0，无 VLAN 下发)</li>
                  <li>WAN 口 SSH 放行 (方便远程管理)</li>
                </ul>
                <p><b>幂等性:</b> 脚本可重复运行，不会产生重复规则。</p>
              </div>
            </el-card>
          </el-col>
          <el-col :span="12">
            <el-card shadow="never">
              <template #header><span style="font-weight: 600">RADIUS 参数</span></template>
              <el-descriptions :column="1" border>
                <el-descriptions-item label="RADIUS 服务器">10.20.0.1</el-descriptions-item>
                <el-descriptions-item label="认证端口">1812</el-descriptions-item>
                <el-descriptions-item label="共享密钥">
                  <code style="background: #f5f7fa; padding: 2px 8px; border-radius: 3px; user-select: all">sunipip_radius_secret</code>
                </el-descriptions-item>
                <el-descriptions-item label="认证方式">WPA2-EAP (PEAP)</el-descriptions-item>
                <el-descriptions-item label="VLAN 模式">v2: disabled (dynamic_vlan=0)</el-descriptions-item>
                <el-descriptions-item label="NSS 加速">已启用 (nss_offload=1)</el-descriptions-item>
              </el-descriptions>
            </el-card>
          </el-col>
        </el-row>
      </el-tab-pane>

      <!-- 事件日志 -->
      <el-tab-pane label="事件日志" name="events">
        <el-table :data="events" v-loading="eventsLoading" stripe>
          <el-table-column label="时间" width="170">
            <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
          </el-table-column>
          <el-table-column prop="event_type" label="类型" width="130">
            <template #default="{ row }">
              <el-tag size="small">{{ row.event_type }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="级别" width="80" align="center">
            <template #default="{ row }">
              <el-tag :type="{ info: '', warning: 'warning', error: 'danger' }[row.severity]" size="small">{{ row.severity }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="message" label="消息" min-width="300" show-overflow-tooltip />
        </el-table>
        <el-pagination v-if="eventsPagination.total > 0" class="mt-16"
          layout="total, prev, pager, next" :total="eventsPagination.total"
          :page-size="eventsPagination.per_page" :current-page="eventsPagination.current_page"
          @current-change="p => { eventsPagination.current_page = p; fetchEvents() }" />
      </el-tab-pane>
    </el-tabs>

    <!-- 绑定客户对话框 -->
    <el-dialog title="绑定客户" v-model="bindVisible" width="460px" destroy-on-close>
      <el-form label-width="70px">
        <el-form-item label="客户" required>
          <el-select v-model="bindForm.customer_id" filterable remote reserve-keyword
            placeholder="搜索客户" :remote-method="searchCustomers" :loading="customerLoading" style="width: 100%">
            <el-option v-for="c in customerOptions" :key="c.id" :label="`${c.customer_name} (${c.company_name || c.phone || c.id})`" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="模块" required>
          <el-select v-model="bindForm.module" style="width: 100%">
            <el-option label="视频专线" value="video" />
            <el-option label="直播专线(手机)" value="live_mobile" />
            <el-option label="直播专线(电脑)" value="live_pc" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="bindVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleBind">确定</el-button>
      </template>
    </el-dialog>

    <!-- 编辑设备对话框 -->
    <el-dialog title="编辑设备" v-model="editVisible" width="460px" destroy-on-close @open="initEditForm">
      <el-form :model="editForm" label-width="100px">
        <el-form-item label="序列号">
          <el-input v-model="editForm.serial_number" placeholder="设备唯一序列号（UUID）">
            <template #append>
              <el-button @click="editForm.serial_number = generateUUID()">自动生成</el-button>
            </template>
          </el-input>
        </el-form-item>
        <el-form-item label="路由器型号">
          <el-select v-model="editForm.router_model_id" clearable placeholder="选择路由器型号" style="width: 100%">
            <el-option v-for="m in catalogOptions.router_models || []" :key="m.id" :label="m.name" :value="m.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="AP 型号">
          <el-select v-model="editForm.ap_model_id" clearable placeholder="选择 AP 型号" style="width: 100%">
            <el-option v-for="m in catalogOptions.ap_models || []" :key="m.id" :label="m.name" :value="m.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="套餐">
          <el-select v-model="editForm.bundle_id" clearable placeholder="选择套餐搭配" style="width: 100%">
            <el-option v-for="b in catalogOptions.bundles || []" :key="b.id" :label="b.name" :value="b.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="WiFi 最大设备" v-if="device.wifi_version >= 2">
          <el-input-number v-model="editForm.wifi_max_devices_per_account" :min="1" :max="50" />
          <div style="font-size: 12px; color: #94a3b8; margin-top: 2px">每个 WiFi 账号最多绑定多少台设备（分配多少个 /32 IP）</div>
        </el-form-item>
        <el-form-item label="灰度 Agent">
          <el-input v-model="editForm.target_agent_version" placeholder="指定版本号(如 1.3.0)，留空跟随全局" clearable />
          <div style="font-size: 12px; color: #94a3b8; margin-top: 2px">设置后此设备心跳将收到该版本号，用于灰度测试新 agent</div>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="editForm.remark" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleEdit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 创建 WiFi 向导 -->
    <el-dialog title="创建 WiFi 账号" v-model="wizardVisible" width="520px" destroy-on-close :close-on-click-modal="false">
      <el-steps :active="wizardStep" finish-status="success" simple style="margin-bottom: 24px">
        <el-step title="选择节点" />
        <el-step title="账号信息" />
        <el-step title="完成" />
      </el-steps>

      <div v-if="wizardStep === 0">
        <p style="font-size: 13px; color: #64748b; margin-bottom: 16px">选择一个代理节点，WiFi 连接的所有流量将通过该节点转发。</p>
        <div v-if="subsLoading" v-loading="true" style="height: 120px" />
        <div v-else-if="availableSubs.length === 0" style="text-align: center; padding: 24px 0; color: #94a3b8; font-size: 13px">
          该客户暂无可用的代理节点。
        </div>
        <div v-else class="node-list">
          <div v-for="s in availableSubs" :key="s.id"
            :class="['node-item', { selected: wifiForm.proxy_subscription_id === s.id }]"
            @click="wifiForm.proxy_subscription_id = s.id">
            <div class="node-main">
              <span class="node-name">{{ s.forward_plan?.name || '代理节点' }}</span>
              <el-tag size="small" type="info">{{ s.proxy_ip?.country_name || '-' }}</el-tag>
            </div>
            <div class="node-detail">
              <span>{{ s.proxy_ip?.ip_address || '-' }}</span>
              <span v-if="s.forward_plan?.display_host" style="color: #64748b">{{ s.forward_plan.display_host }}</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="wizardStep === 1">
        <el-form :model="wifiForm" label-width="90px">
          <el-form-item label="WiFi 名称">
            <el-input :model-value="'SunIPIP.com Streaming LAN'" disabled />
            <div style="font-size: 12px; color: #94a3b8; margin-top: 2px">WiFi SSID 固定为 SunIPIP.com Streaming LAN，此标签仅用于列表区分</div>
          </el-form-item>
          <el-form-item label="登录用户名">
            <el-input v-model="wifiForm.username" />
          </el-form-item>
          <el-form-item label="登录密码">
            <el-input v-model="wifiForm.password" />
          </el-form-item>
          <el-form-item label="最大设备数">
            <el-input-number v-model="wifiForm.max_devices" :min="1" :max="device.wifi_max_devices_per_account || 5" />
            <div style="font-size: 12px; color: #94a3b8; margin-top: 2px">每台设备分配一个独立 /32 IP（上限 {{ device.wifi_max_devices_per_account || 5 }}，可在设备编辑中调整）</div>
          </el-form-item>
        </el-form>
      </div>

      <div v-if="wizardStep === 2" style="text-align: center">
        <div style="font-size: 48px; color: #67c23a; margin-bottom: 8px">OK</div>
        <h3 style="margin: 0; color: #1e293b">WiFi 账号创建成功</h3>
        <el-descriptions :column="1" border size="small" style="margin-top: 16px">
          <el-descriptions-item label="WiFi 名称">SunIPIP.com Streaming LAN</el-descriptions-item>
          <el-descriptions-item label="用户名">{{ createdAccount?.username }}</el-descriptions-item>
          <el-descriptions-item label="密码">{{ createdAccount?.password }}</el-descriptions-item>
          <el-descriptions-item label="代理节点">{{ selectedNodeLabel }}</el-descriptions-item>
        </el-descriptions>
      </div>

      <template #footer>
        <template v-if="wizardStep === 0">
          <el-button @click="wizardVisible = false">取消</el-button>
          <el-button type="primary" :disabled="!wifiForm.proxy_subscription_id" @click="wizardStep = 1">下一步</el-button>
        </template>
        <template v-else-if="wizardStep === 1">
          <el-button @click="wizardStep = 0">上一步</el-button>
          <el-button type="primary" :loading="submitting" @click="handleWizardCreate">创建账号</el-button>
        </template>
        <template v-else>
          <el-button @click="showWifiGuide(createdAccount)">查看连接信息</el-button>
          <el-button type="primary" @click="wizardVisible = false">完成</el-button>
        </template>
      </template>
    </el-dialog>

    <!-- 编辑 WiFi 对话框 -->
    <el-dialog title="编辑 WiFi 账号" v-model="wifiEditVisible" width="500px" destroy-on-close>
      <el-form :model="wifiEditForm" label-width="90px">
        <el-form-item label="用户名">
          <el-input v-model="wifiEditForm.username" />
        </el-form-item>
        <el-form-item label="密码">
          <el-input v-model="wifiEditForm.password" />
        </el-form-item>
        <el-form-item label="标签">
          <el-input v-model="wifiEditForm.label" placeholder="如：客厅电视" />
        </el-form-item>
        <el-form-item label="代理节点">
          <el-select v-model="wifiEditForm.proxy_subscription_id" clearable placeholder="选择代理节点" style="width: 100%"
            :loading="subsLoading">
            <el-option v-for="s in editAvailableSubs" :key="s.id" :label="subOptionLabel(s)" :value="s.id">
              <div style="display: flex; justify-content: space-between; align-items: center">
                <span>{{ s.forward_plan?.name || '代理节点' }}</span>
                <span style="color: #94a3b8; font-size: 12px">{{ s.proxy_ip?.ip_address || '' }} · {{ s.proxy_ip?.country_name || '' }}</span>
              </div>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="最大设备数">
          <el-input-number v-model="wifiEditForm.max_devices" :min="1" :max="device.wifi_max_devices_per_account || 5" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="wifiEditForm.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="wifiEditVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleWifiEditSubmit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 安装令牌结果 -->
    <el-dialog title="安装令牌" v-model="tokenVisible" width="620px">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        此令牌有效期 72 小时，注册后自动失效。请妥善保管，勿泄露给无关人员。
      </el-alert>

      <div class="install-section">
        <div class="install-label">安装链接</div>
        <el-input v-model="installUrl" readonly>
          <template #append><el-button @click="copyText(installUrl)">复制</el-button></template>
        </el-input>
      </div>

      <div class="install-section">
        <div class="install-label">一键安装命令</div>
        <el-input :model-value="installCmd" readonly type="textarea" :rows="2" resize="none" />
        <el-button size="small" style="margin-top: 4px" @click="copyText(installCmd)">复制命令</el-button>
      </div>

      <el-divider />

      <div class="install-guide">
        <div class="install-label">安装步骤</div>
        <ol>
          <li>将工控机 4 个网口按顺序连接：<b>eth0</b>(WAN 上网) · <b>eth1</b>(管理口) · <b>eth2</b>(接 AP) · <b>eth3</b>(有线 LAN)</li>
          <li>确保工控机已安装 <b>Debian 12</b>，并能通过 eth0 上网</li>
          <li>SSH 登录工控机（root），执行上方的一键安装命令</li>
          <li>脚本将自动安装依赖、生成 WG 密钥、向平台注册、配置网络和防火墙</li>
          <li>安装完成后，设备状态变为「已配置」，等待 Agent 部署后上线</li>
        </ol>
        <el-alert type="info" :closable="false" style="margin-top: 8px">
          <template #title>
            <span style="font-weight: normal">安装完成后，管理页面: <b>http://172.10.0.1</b> · 有线LAN: <b>http://192.168.1.1</b></span>
          </template>
        </el-alert>
      </div>
    </el-dialog>

    <!-- WiFi 连接指南 -->
    <el-dialog title="WiFi 连接信息" v-model="wifiGuideVisible" width="480px">
      <template v-if="wifiGuideAccount">
        <el-descriptions :column="1" border size="small">
          <el-descriptions-item label="WiFi 名称">SunIPIP.com Streaming LAN</el-descriptions-item>
          <el-descriptions-item label="安全类型">WPA2-Enterprise</el-descriptions-item>
          <el-descriptions-item label="EAP 方法">TTLS / PAP</el-descriptions-item>
          <el-descriptions-item label="用户名">
            {{ wifiGuideAccount.username }}
            <el-button size="small" link style="margin-left: 8px" @click="copyText(wifiGuideAccount.username)">复制</el-button>
          </el-descriptions-item>
          <el-descriptions-item label="密码">
            {{ wifiGuideAccount.password }}
            <el-button size="small" link style="margin-left: 8px" @click="copyText(wifiGuideAccount.password)">复制</el-button>
          </el-descriptions-item>
          <el-descriptions-item label="VLAN" v-if="device.wifi_version < 2">{{ wifiGuideAccount.vlan_id }}</el-descriptions-item>
          <el-descriptions-item label="子网">{{ wifiGuideAccount.ip_prefix }}</el-descriptions-item>
          <el-descriptions-item label="代理模式">{{ wifiGuideAccount.proxy_mode === 'proxy' ? '代理' : '直连' }}</el-descriptions-item>
        </el-descriptions>
        <el-divider />
        <div style="font-size: 13px; color: #606266; line-height: 1.8">
          <p><b>Android / 电脑:</b> WiFi 设置 → 选择「SunIPIP.com Streaming LAN」→ 安全类型选 WPA2-Enterprise → EAP 方法 TTLS → 阶段2 PAP → 输入用户名和密码</p>
          <p><b>iPhone / iPad:</b> 推荐使用 iOS 描述文件自动配置（客户端可下载）</p>
        </div>
      </template>
    </el-dialog>

    <!-- 重启服务对话框 -->
    <el-dialog title="重启服务" v-model="restartDialogVisible" width="400px" destroy-on-close>
      <el-form label-width="60px">
        <el-form-item label="服务">
          <el-select v-model="restartServiceName" style="width: 100%">
            <el-option label="Clash (代理引擎)" value="clash" />
            <el-option label="FreeRadius (WiFi 认证)" value="freeradius" />
            <el-option label="dnsmasq (DHCP/DNS)" value="dnsmasq" />
            <el-option label="Agent (设备代理)" value="sunipip-router-agent" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="restartDialogVisible = false">取消</el-button>
        <el-button type="warning" :loading="submitting" @click="handleRestartService">重启</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'

import dayjs from 'dayjs'
import {
  getRouterDevice, updateRouterDevice, deleteRouterDevice,
  generateInstallToken, bindDevice, unbindDevice, pushConfig,
  getDeviceEvents, getDeviceWifiAccounts, getAvailableSubscriptions,
  createWifiAccount, updateWifiAccount, deleteWifiAccount,
  rebootDevice, restartService, toggleTrunkDhcp, cleanStaleConnections,
} from '@/api/routerDevices'

console.log('OEM Contact edward.sun@as204921.net')
import { getCustomers } from '@/api/customers'
import { getRouterCatalogOptions } from '@/api/routerCatalog'

const route = useRoute()
const deviceId = route.params.id

const device = ref(null)
const loading = ref(false)
const submitting = ref(false)
const activeTab = ref('overview')

// WiFi
const wifiAccounts = ref([])
const wifiLoading = ref(false)
const wifiPage = ref(1)
const wifiPageSize = 15
const paginatedWifi = computed(() => {
  const start = (wifiPage.value - 1) * wifiPageSize
  return wifiAccounts.value.slice(start, start + wifiPageSize)
})
const availableSubs = ref([])
const subsLoading = ref(false)

// WiFi wizard (create)
const wizardVisible = ref(false)
const wizardStep = ref(0)
const createdAccount = ref(null)
const wifiForm = reactive({
  username: '', password: '', label: '', proxy_mode: 'proxy',
  proxy_subscription_id: null, max_devices: 5,
})

// WiFi edit
const wifiEditVisible = ref(false)
const editingWifi = ref(null)
const editAvailableSubs = ref([])
const wifiEditForm = reactive({
  username: '', password: '', label: '', proxy_mode: 'proxy',
  proxy_subscription_id: null, max_devices: 5, is_active: 1,
})

// Events
const events = ref([])
const eventsLoading = ref(false)
const eventsPagination = reactive({ total: 0, per_page: 20, current_page: 1 })

// Bind
const bindVisible = ref(false)
const bindForm = reactive({ customer_id: null, module: 'video' })
const customerOptions = ref([])
const customerLoading = ref(false)

// Edit
const editVisible = ref(false)
const editForm = reactive({ serial_number: '', remark: '', router_model_id: null, ap_model_id: null, bundle_id: null, target_agent_version: '', wifi_max_devices_per_account: 5 })
const catalogOptions = ref({})

// Install token
const tokenVisible = ref(false)
const installUrl = ref('')
const installCmd = computed(() => installUrl.value ? `curl -fsSL '${installUrl.value}' | bash` : '')

// WiFi guide
const wifiGuideVisible = ref(false)
const wifiGuideAccount = ref(null)

// Trunk DHCP toggle
const trunkDhcpLoading = ref(false)

// Clean stale connections
const cleaningStale = ref(false)
async function handleCleanStale() {
  await ElMessageBox.confirm(
    '将清理设备上所有不活跃的 MAC 连接，释放 IP 给新设备。正在使用中的设备不会受影响。',
    '一键清理残留连接',
    { type: 'warning', confirmButtonText: '确认清理', cancelButtonText: '取消' }
  )
  cleaningStale.value = true
  try {
    await cleanStaleConnections(deviceId)
    ElMessage.success('清理命令已下发，设备将在下次心跳时执行')
  } catch { /* handled */ }
  finally { cleaningStale.value = false }
}

// Remote ops
const restartDialogVisible = ref(false)
const restartServiceName = ref('clash')

// AP setup script — synced with scripts/ap-config.sh (v2)
const apSetupScript = computed(() => {
  return `#!/bin/sh
# ============================================================
#  SuniPIP AP v2 配置脚本 — ImmortalWrt / OpenWrt
#  Flat IP + NSS 硬件加速 + WPA-Enterprise
# ============================================================
set -e

SSID="SunIPIP.com Streaming LAN"
RADIUS_SECRET="sunipip_radius_secret"
RADIUS_PORT="1812"
NSS_CHANGED=0

log()  { echo "[INFO]  $*"; }
ok()   { echo "[  OK]  $*"; }
warn() { echo "[WARN]  $*"; }

echo ""
echo "============================================================"
echo "  SuniPIP AP v2 配置脚本 (Flat IP + NSS)"
echo "============================================================"
echo ""

# ---- 1. 启用 NSS offload (硬件加速) ----
if [ -f /etc/modules.d/ath11k ]; then
    echo 'ath11k nss_offload=1 frame_mode=2' > /etc/modules.d/ath11k
    ok "ath11k nss_offload=1 frame_mode=2"
    NSS_CHANGED=1

    for f in 51-qca-nss-drv-vlan-mgr 51-qca-nss-drv-bridge-mgr; do
        if [ -f "/etc/modules.d/$f" ]; then
            if grep -q "disabled" "/etc/modules.d/$f" 2>/dev/null; then
                MOD=$(echo "$f" | sed 's/^[0-9]*-//' | tr '-' '_')
                echo "$MOD" > "/etc/modules.d/$f"
                ok "Re-enabled $f"
            fi
        fi
    done

    rm -f /etc/modprobe.d/sunipip-no-nss-vlan.conf
    rm -f /etc/modprobe.d/blacklist-ath11k-pci.conf
    ok "已移除 v1 NSS 黑名单"
fi

# ---- 2. 自动检测 RADIUS 服务器 (默认网关) ----
ROUTER_IP=$(ip route 2>/dev/null | awk '/default/{print $3}' | head -1)
[ -z "$ROUTER_IP" ] && ROUTER_IP="10.20.0.1"
log "RADIUS 服务器: \${ROUTER_IP}:\${RADIUS_PORT}"

# ---- 3. 网络配置 (br-trunk + WAN) ----
uci delete network.wan6 2>/dev/null || true
uci delete network.globals.ula_prefix 2>/dev/null || true

# 从 br-lan 移除 wan 端口（出厂可能在 br-lan 里）
IDX=0
while uci -q get "network.@device[\${IDX}]" >/dev/null 2>&1; do
    NAME=$(uci -q get "network.@device[\${IDX}].name")
    if [ "$NAME" = "br-lan" ]; then
        uci del_list "network.@device[\${IDX}].ports=wan" 2>/dev/null
        ok "从 br-lan 移除 wan 端口"
        break
    fi
    IDX=$((IDX + 1))
done

# 创建 br-trunk 桥接
TRUNK_EXISTS=0; IDX=0
while uci -q get "network.@device[\${IDX}]" >/dev/null 2>&1; do
    NAME=$(uci -q get "network.@device[\${IDX}].name")
    [ "$NAME" = "br-trunk" ] && TRUNK_EXISTS=1 && break
    IDX=$((IDX + 1))
done
if [ "$TRUNK_EXISTS" = "0" ]; then
    uci add network device
    uci set "network.@device[-1].name=br-trunk"
    uci set "network.@device[-1].type=bridge"
    uci add_list "network.@device[-1].ports=wan"
    ok "创建 br-trunk (wan)"
fi
uci set network.wan.device='br-trunk'
uci set network.wan.proto='static'
uci set network.wan.ipaddr='10.20.0.120'
uci set network.wan.netmask='255.255.255.0'
uci set network.wan.gateway='10.20.0.1'
uci set network.wan.dns='223.5.5.5 119.29.29.29'

# 关闭 AP 自身 DHCP
uci set dhcp.lan.ignore='1'
ok "网络配置完成 (静态 IP 10.20.0.120, DHCP 已关闭)"

# ---- 4. 无线配置 (v2: dynamic_vlan=0) ----
log "配置 WPA-Enterprise (Flat IP, 无 VLAN)..."

# 先删除所有出厂 wifi-iface（可能叫 wifinet0 等，绑 br-lan）
while uci -q get "wireless.@wifi-iface[0]" >/dev/null 2>&1; do
    uci delete "wireless.@wifi-iface[0]"
done
ok "清除所有出厂 wifi-iface"

# 为每个 radio 创建全新的 wifi-iface，绑定 wan (br-trunk)
for radio in radio0 radio1 radio2 radio3; do
    uci -q get "wireless.\${radio}" >/dev/null 2>&1 || continue
    IFACE="default_\${radio}"

    uci set "wireless.\${IFACE}=wifi-iface"
    uci set "wireless.\${IFACE}.device=\${radio}"
    uci set "wireless.\${IFACE}.mode=ap"

    uci set "wireless.\${radio}.disabled=0"
    uci set "wireless.\${radio}.country=HK"

    RADIO_PATH=$(uci -q get "wireless.\${radio}.path" 2>/dev/null || echo "")
    IS_PCIE=0
    echo "$RADIO_PATH" | grep -q "pci" && IS_PCIE=1

    BAND=$(uci -q get "wireless.\${radio}.band" 2>/dev/null || echo "")
    case "$BAND" in
        2g)
            uci set "wireless.\${radio}.channel=6"
            uci set "wireless.\${radio}.htmode=HE40"
            uci set "wireless.\${radio}.noscan=1"
            ok "\${radio}: 2.4GHz / HE40 / CH6"
            ;;
        5g)
            if [ "$IS_PCIE" = "1" ]; then
                uci set "wireless.\${radio}.channel=36"
                uci set "wireless.\${radio}.htmode=HE160"
                ok "\${radio}: PCIe 5GHz / HE160 / CH36 (NSS enabled)"
            else
                uci set "wireless.\${radio}.channel=149"
                uci set "wireless.\${radio}.htmode=HE80"
                ok "\${radio}: SoC 5GHz / HE80 / CH149"
            fi
            ;;
        6g)
            uci set "wireless.\${radio}.channel=1"
            uci set "wireless.\${radio}.htmode=HE160"
            ok "\${radio}: 6GHz / HE160"
            ;;
        *)
            uci set "wireless.\${radio}.htmode=HE20"
            warn "\${radio}: 未识别频段"
            ;;
    esac

    uci set "wireless.\${IFACE}.ssid=\${SSID}"
    uci set "wireless.\${IFACE}.encryption=wpa2+ccmp"
    uci set "wireless.\${IFACE}.network=wan"
    uci set "wireless.\${IFACE}.auth_server=\${ROUTER_IP}"
    uci set "wireless.\${IFACE}.auth_port=\${RADIUS_PORT}"
    uci set "wireless.\${IFACE}.auth_secret=\${RADIUS_SECRET}"
    uci set "wireless.\${IFACE}.dynamic_vlan=0"
    uci set "wireless.\${IFACE}.ieee80211w=1"
    ok "\${radio}: WPA2-Enterprise → wan (br-trunk)"
done

# ---- 5. 防火墙 ----
ZONE_IDX=0
while uci -q get "firewall.@zone[\${ZONE_IDX}]" >/dev/null 2>&1; do
    ZNAME=$(uci -q get "firewall.@zone[\${ZONE_IDX}].name")
    if [ "$ZNAME" = "wan" ]; then
        uci set "firewall.@zone[\${ZONE_IDX}].masq=0"
        uci set "firewall.@zone[\${ZONE_IDX}].input=ACCEPT"
        uci set "firewall.@zone[\${ZONE_IDX}].forward=ACCEPT"
        ok "WAN zone: 禁用 NAT，开放转发"
        break
    fi
    ZONE_IDX=$((ZONE_IDX + 1))
done

if ! uci show firewall 2>/dev/null | grep -q "Allow-SSH-WAN"; then
    uci add firewall rule >/dev/null
    uci set firewall.@rule[-1].name='Allow-SSH-WAN'
    uci set firewall.@rule[-1].src='wan'
    uci set firewall.@rule[-1].dest_port='22'
    uci set firewall.@rule[-1].proto='tcp'
    uci set firewall.@rule[-1].target='ACCEPT'
    ok "WAN SSH 已开放"
fi

# ---- 6. 提交并重启 ----
uci commit network
uci commit wireless
uci commit dhcp
uci commit firewall
/etc/init.d/network restart
sleep 3
wifi down; sleep 2; wifi up; sleep 5
/etc/init.d/firewall restart
ok "配置已应用"

echo ""
echo "============================================================"
echo "  v2 配置完成!"
echo "  WiFi SSID:     \${SSID}"
echo "  RADIUS:        \${ROUTER_IP}:\${RADIUS_PORT}"
echo "  dynamic_vlan:  0 (Flat IP)"
echo "  NSS offload:   1 (硬件加速)"
echo "============================================================"

if [ "\${NSS_CHANGED}" = "1" ]; then
    echo ""
    echo "  ⚠  NSS 模块配置已更改，需要重启: reboot"
fi`
})

function copyApScript() {
  navigator.clipboard.writeText(apSetupScript.value).then(() => {
    ElMessage.success('脚本已复制')
  })
}

function copyApSshCmd() {
  const ip = device.value?.ap_ip || '<AP_IP>'
  const cmd = "ssh root@" + ip + " 'sh -s' << 'SETUP_EOF'\n" + apSetupScript.value + "\nSETUP_EOF"
  navigator.clipboard.writeText(cmd).then(() => {
    ElMessage.success('SSH 命令已复制，粘贴到终端即可执行')
  })
}

onMounted(() => { fetchDevice(); fetchCatalogOptions() })

async function fetchCatalogOptions() {
  try {
    catalogOptions.value = await getRouterCatalogOptions() || {}
  } catch { /* handled */ }
}

watch(activeTab, (tab) => {
  if (tab === 'wifi' && wifiAccounts.value.length === 0) fetchWifi()
  if (tab === 'events' && events.value.length === 0) fetchEvents()
})

async function fetchDevice() {
  loading.value = true
  try {
    device.value = await getRouterDevice(deviceId)
  } catch { /* handled */ }
  finally { loading.value = false }
}

async function fetchWifi() {
  wifiLoading.value = true
  try {
    wifiAccounts.value = await getDeviceWifiAccounts(deviceId) || []
  } catch { /* handled */ }
  finally { wifiLoading.value = false }
}

async function fetchEvents() {
  eventsLoading.value = true
  try {
    const res = await getDeviceEvents(deviceId, {
      per_page: eventsPagination.per_page, page: eventsPagination.current_page,
    })
    events.value = res?.items || []
    Object.assign(eventsPagination, res?.pagination || {})
  } catch { /* handled */ }
  finally { eventsLoading.value = false }
}

async function searchCustomers(kw) {
  customerLoading.value = true
  try {
    const params = { per_page: 30 }
    if (kw) params['filter[keyword]'] = kw
    const res = await getCustomers(params)
    customerOptions.value = res?.items || []
  } catch { /* handled */ }
  finally { customerLoading.value = false }
}

async function handleBind() {
  if (!bindForm.customer_id) return ElMessage.warning('请选择客户')
  submitting.value = true
  try {
    await bindDevice(deviceId, bindForm)
    ElMessage.success('已绑定')
    bindVisible.value = false
    fetchDevice()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleUnbind() {
  await ElMessageBox.confirm('解绑将删除所有 WiFi 账号并重新生成配置，确认？', '解绑客户', { type: 'warning' })
  try {
    await unbindDevice(deviceId)
    ElMessage.success('已解绑')
    fetchDevice()
    wifiAccounts.value = []
  } catch { /* handled */ }
}

async function handlePushConfig() {
  try {
    await pushConfig(deviceId)
    ElMessage.success('配置已推送')
    fetchDevice()
  } catch { /* handled */ }
}

async function handleInstallToken() {
  try {
    const isReinstall = !['inventory', 'provisioned'].includes(device.value?.status)
    if (isReinstall) {
      await ElMessageBox.confirm(
        '重装将重置设备的 Agent 密钥，旧系统上的 Agent 将无法连接。确认继续？',
        '重装确认',
        { type: 'warning' }
      )
    }
    const res = await generateInstallToken(deviceId)
    installUrl.value = res?.install_url || ''
    tokenVisible.value = true
    if (isReinstall) fetchDevice()
  } catch { /* handled */ }
}

async function handleDecommission() {
  await ElMessageBox.confirm('停用后设备将无法连接，确认停用？', '停用设备', { type: 'warning' })
  try {
    await deleteRouterDevice(deviceId)
    ElMessage.success('设备已停用')
    fetchDevice()
  } catch { /* handled */ }
}

function initEditForm() {
  if (!device.value) return
  Object.assign(editForm, {
    serial_number: device.value.serial_number || '',
    remark: device.value.remark || '',
    router_model_id: device.value.router_model_id || null,
    ap_model_id: device.value.ap_model_id || null,
    bundle_id: device.value.bundle_id || null,
    target_agent_version: device.value.target_agent_version || '',
    wifi_max_devices_per_account: device.value.wifi_max_devices_per_account || 5,
  })
}

async function handleEdit() {
  submitting.value = true
  try {
    const payload = { ...editForm, target_agent_version: editForm.target_agent_version || null }
    await updateRouterDevice(deviceId, payload)
    ElMessage.success('已更新')
    editVisible.value = false
    fetchDevice()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

const selectedNodeLabel = computed(() => {
  const s = availableSubs.value.find(s => s.id === wifiForm.proxy_subscription_id)
  if (!s) return '-'
  return `${s.forward_plan?.name || '代理节点'} (${s.proxy_ip?.country_name || ''} ${s.proxy_ip?.ip_address || ''})`
})

function genRandomId() {
  return String(Math.floor(100 + Math.random() * 900))
}

async function fetchAvailableSubs() {
  subsLoading.value = true
  try {
    availableSubs.value = await getAvailableSubscriptions(deviceId) || []
  } catch { /* handled */ }
  finally { subsLoading.value = false }
}

function openWifiWizard() {
  wizardStep.value = 0
  createdAccount.value = null
  const rid = genRandomId()
  Object.assign(wifiForm, {
    username: `sunip-${rid}`, password: `sunip-${rid}`,
    label: wifiAccounts.value.length === 0 ? 'SunIPIP.com Streaming LAN' : '',
    proxy_mode: 'proxy', proxy_subscription_id: null, max_devices: device.value?.wifi_max_devices_per_account || 5,
  })
  fetchAvailableSubs()
  wizardVisible.value = true
}

async function handleWizardCreate() {
  submitting.value = true
  try {
    const result = await createWifiAccount(deviceId, wifiForm)
    createdAccount.value = result
    wizardStep.value = 2
    fetchWifi()
    fetchDevice()
    ElMessage.success('WiFi 账号已创建')
  } catch { /* handled */ }
  finally { submitting.value = false }
}

function openWifiEditDialog(row) {
  editingWifi.value = row
  Object.assign(wifiEditForm, {
    username: row.username, password: row.password, label: row.label || '',
    proxy_mode: row.proxy_mode, proxy_subscription_id: row.proxy_subscription_id,
    max_devices: row.max_devices, is_active: row.is_active,
  })
  subsLoading.value = true
  getAvailableSubscriptions(deviceId).then(subs => {
    const list = subs || []
    if (row.proxy_subscription_id && !list.find(s => s.id === row.proxy_subscription_id)) {
      list.unshift({ id: row.proxy_subscription_id, ...(row.subscription || {}) })
    }
    editAvailableSubs.value = list
  }).finally(() => { subsLoading.value = false })
  wifiEditVisible.value = true
}

async function handleWifiEditSubmit() {
  submitting.value = true
  try {
    await updateWifiAccount(editingWifi.value.id, wifiEditForm)
    ElMessage.success('已更新')
    wifiEditVisible.value = false
    fetchWifi()
    fetchDevice()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

function subOptionLabel(s) {
  const plan = s.forward_plan?.name || '代理节点'
  const ip = s.proxy_ip?.ip_address || ''
  return `${plan} (${ip})`
}

async function handleDeleteWifi(row) {
  await ElMessageBox.confirm(`确认删除 WiFi 账号「${row.username}」？`, '确认', { type: 'warning' })
  try {
    await deleteWifiAccount(row.id)
    ElMessage.success('已删除')
    fetchWifi()
    fetchDevice()
  } catch { /* handled */ }
}

function showWifiGuide(account) {
  wifiGuideAccount.value = account
  wifiGuideVisible.value = true
}

async function handleToggleTrunkDhcp(enabled) {
  const action = enabled ? '开启' : '关闭'
  await ElMessageBox.confirm(
    enabled
      ? '临时开启管理段 DHCP (10.20.0.100-200)，用于连接 AP 进行管理。完成后请手动关闭。'
      : '关闭管理段 DHCP，WiFi 客户端将仅通过 RADIUS 认证获取业务 IP。',
    `${action}管理段 DHCP`,
    { type: enabled ? 'warning' : 'info' }
  )
  trunkDhcpLoading.value = true
  try {
    const res = await toggleTrunkDhcp(deviceId, { enabled })
    ElMessage.success(res?.message || `${action}命令已下发`)
  } catch { /* handled */ }
  finally { trunkDhcpLoading.value = false }
}

async function handleReboot() {
  await ElMessageBox.confirm('确认远程重启此设备？设备将暂时离线。', '重启设备', { type: 'warning' })
  try {
    await rebootDevice(deviceId)
    ElMessage.success('重启命令已发送')
  } catch { /* handled */ }
}

async function handleRestartService() {
  submitting.value = true
  try {
    await restartService(deviceId, { service: restartServiceName.value })
    ElMessage.success(`服务 ${restartServiceName.value} 重启命令已发送`)
    restartDialogVisible.value = false
  } catch { /* handled */ }
  finally { submitting.value = false }
}

function ipRange(row) {
  if (!row.ip_start_index || row.ip_start_index < 2) return row.ip_prefix
  const base = (10 << 24) | (10 << 16)
  const first = base + row.ip_start_index
  const last = first + (row.max_devices || 1) - 1
  const toIp = n => `${(n >> 24) & 255}.${(n >> 16) & 255}.${(n >> 8) & 255}.${n & 255}`
  return `${toIp(first)} ~ ${toIp(last)}`
}

function generateUUID() {
  return crypto.randomUUID().toUpperCase()
}

function copyText(text) {
  navigator.clipboard.writeText(text)
  ElMessage.success('已复制')
}

function formatTime(t) {
  if (!t) return '-'
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

function formatUptime(seconds) {
  if (!seconds) return '-'
  const d = Math.floor(seconds / 86400)
  const h = Math.floor((seconds % 86400) / 3600)
  return d > 0 ? `${d}天${h}小时` : `${h}小时`
}

function statusLabel(s) {
  return { inventory: '库存', provisioned: '已配置', online: '在线', offline: '离线', decommissioned: '已停用' }[s] || s
}
function statusType(s) {
  return { inventory: 'info', provisioned: '', online: 'success', offline: 'danger', decommissioned: 'info' }[s] || ''
}
function moduleLabel(m) {
  return { video: '视频专线', live_mobile: '直播(手机)', live_pc: '直播(电脑)' }[m] || ''
}
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
.page-header h2 { margin: 0 0 4px; }
.header-actions { display: flex; gap: 8px; }
.text-muted { color: #909399; font-size: 13px; margin: 0; }
.ml-4 { margin-left: 4px; }
.ml-8 { margin-left: 8px; }
.mt-16 { margin-top: 16px; }
.install-section { margin-bottom: 16px; }
.install-label { font-size: 13px; font-weight: 600; color: #303133; margin-bottom: 6px; }
.install-guide { font-size: 13px; color: #606266; line-height: 1.8; }
.install-guide ol { padding-left: 20px; margin: 4px 0 0; }
.install-guide li { margin-bottom: 4px; }
.node-list { display: flex; flex-direction: column; gap: 8px; max-height: 320px; overflow-y: auto; }
.node-item { border: 2px solid #e2e8f0; border-radius: 8px; padding: 12px; cursor: pointer; transition: all .2s; }
.node-item:hover { border-color: #a0aec0; }
.node-item.selected { border-color: #409eff; background: #f0f7ff; }
.node-main { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.node-name { font-weight: 600; color: #1e293b; }
.node-detail { font-size: 12px; color: #94a3b8; display: flex; gap: 12px; }
.ap-script-block {
  background: #1e1e2e; color: #cdd6f4; border-radius: 8px; padding: 16px 20px;
  font-size: 12.5px; line-height: 1.6; overflow-x: auto; margin: 0;
  font-family: 'SF Mono', Monaco, Menlo, Consolas, monospace;
  max-height: 520px; overflow-y: auto;
}
.ap-script-block code { color: inherit; background: none; padding: 0; font-size: inherit; }
</style>
