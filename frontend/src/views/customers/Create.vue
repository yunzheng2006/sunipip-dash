<template>
  <div class="customer-create">
    <div class="page-header">
      <el-button @click="$router.back()" :icon="ArrowLeft">返回</el-button>
      <h2 class="page-title">新建客户</h2>
    </div>

    <el-card>
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="100px"
        style="max-width: 600px"
      >
        <el-form-item label="客户名称" prop="customer_name">
          <el-input v-model="form.customer_name" placeholder="请输入客户名称" />
        </el-form-item>
        <el-form-item label="用户名" prop="username">
          <el-input v-model="form.username" placeholder="留空自动生成" />
          <div class="form-hint">留空将自动生成用户名</div>
        </el-form-item>
        <el-form-item label="密码" prop="password">
          <el-input v-model="form.password" placeholder="留空自动生成" />
          <div class="form-hint">留空将自动生成随机密码</div>
        </el-form-item>
        <el-form-item label="手机号" prop="phone">
          <el-input v-model="form.phone" placeholder="请输入手机号" />
        </el-form-item>
        <el-form-item label="邮箱" prop="email">
          <el-input v-model="form.email" placeholder="请输入邮箱" />
        </el-form-item>
        <el-form-item label="公司名称" prop="company_name">
          <el-input v-model="form.company_name" placeholder="请输入公司名称" />
        </el-form-item>
        <el-form-item label="公司编号" prop="company_id">
          <el-input v-model="form.company_id" placeholder="请输入公司编号/税号" />
        </el-form-item>
        <el-form-item label="地址" prop="address">
          <el-input v-model="form.address" placeholder="请输入地址" />
        </el-form-item>
        <el-form-item label="业务归属" prop="sales_person">
          <el-input v-model="form.sales_person" placeholder="请输入业务归属人" />
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="form.remark" type="textarea" :rows="3" placeholder="备注信息" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="handleSubmit">创建客户</el-button>
          <el-button @click="$router.back()">取消</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- Success Dialog -->
    <el-dialog v-model="successDialogVisible" title="客户创建成功" width="450px" :close-on-click-modal="false">
      <el-descriptions :column="1" border>
        <el-descriptions-item label="客户名称">{{ createdData.customer_name }}</el-descriptions-item>
        <el-descriptions-item label="用户名">
          <div class="copy-field">
            <span>{{ createdData.username }}</span>
            <el-button type="primary" link size="small" @click="copyText(createdData.username)">复制</el-button>
          </div>
        </el-descriptions-item>
        <el-descriptions-item label="密码">
          <div class="copy-field">
            <span>{{ createdData.password }}</span>
            <el-button type="primary" link size="small" @click="copyText(createdData.password)">复制</el-button>
          </div>
        </el-descriptions-item>
      </el-descriptions>
      <div class="dialog-tip">
        <el-icon><Warning /></el-icon>
        请妥善保存以上账号信息，密码创建后不可再次查看。
      </div>
      <template #footer>
        <el-button @click="copyAll">复制全部信息</el-button>
        <el-button type="primary" @click="goToList">返回列表</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { createCustomer } from '@/api/customers'

const router = useRouter()
const formRef = ref(null)
const submitting = ref(false)
const successDialogVisible = ref(false)

const form = reactive({
  customer_name: '',
  username: '',
  password: '',
  phone: '',
  email: '',
  company_name: '',
  company_id: '',
  address: '',
  sales_person: '',
  remark: '',
})

const createdData = ref({})

const rules = {
  customer_name: [{ required: true, message: '请输入客户名称', trigger: 'blur' }],
}

async function handleSubmit() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    // Remove empty fields
    const data = {}
    Object.keys(form).forEach((k) => {
      if (form[k] !== '' && form[k] != null) {
        data[k] = form[k]
      }
    })
    const res = await createCustomer(data)
    // 后端返回格式: { customer: {...}, credentials: { username, password } }
    createdData.value = {
      customer_name: res.customer?.customer_name || form.customer_name,
      username: res.credentials?.username || res.customer?.username || '',
      password: res.credentials?.password || '',
    }
    successDialogVisible.value = true
  } catch {
    // Error handled by interceptor
  } finally {
    submitting.value = false
  }
}

function copyText(text) {
  navigator.clipboard.writeText(text).then(() => {
    ElMessage.success('已复制到剪贴板')
  })
}

function copyAll() {
  const text = `客户名称: ${createdData.value.customer_name}\n用户名: ${createdData.value.username}\n密码: ${createdData.value.password}`
  navigator.clipboard.writeText(text).then(() => {
    ElMessage.success('已复制全部信息')
  })
}

function goToList() {
  successDialogVisible.value = false
  router.push('/customers')
}
</script>

<style lang="scss" scoped>
.customer-create {
  .page-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;

    .page-title {
      margin: 0;
      font-size: 20px;
      color: #303133;
    }
  }

  .form-hint {
    font-size: 12px;
    color: #909399;
    line-height: 1.5;
  }

  .copy-field {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
  }

  .dialog-tip {
    margin-top: 16px;
    padding: 12px;
    background: #fdf6ec;
    border-radius: 4px;
    color: #e6a23c;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
}
</style>
