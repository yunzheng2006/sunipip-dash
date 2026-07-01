

## 账号和密码

### (POST) /api/v1/auth/login

> 用于登陆面板，获取身份 token

请求头：无

请求体（application/json）：

```json
{
	"username": "<你的账户名>",
	"password": "<你的账户密码>"
}
```

> 注意：账户密码是明文传输的，没有加密

---

返回结构：

```json
{
  "code": 0,
  "data": "53e1a26c-92c1-4016-8b85-bc5f1e574e5e",
  "msg": "登录成功"
}
```

| 字段 | 值        | 介绍                 |
| ---- | --------- | -------------------- |
| code | enum[int] | 状态码               |
| data | string    | 登陆成功后的账户凭据 |
| msg  | string    | 附带消息             |



`code` 字段：

| 枚举值 | 含义                                  |
| ------ | ------------------------------------- |
| 0      | 登陆成功                              |
| 403    | 用户名或密码错误                      |
| 400    | Username为必填字段/Password为必填字段 |

> 如果载荷中 `username` 为空，则 `code` 返回 400，同时 `msg` 返回 `Username为必填字段`
>
> 如果是 `password` ，则同样 `code` 返回 400，但是 `msg` 返回 `Password为必填字段`
>
> 如果两个都没有填， `code` 照样返回 400，但是 `msg` 返回 `Username为必填字段\nPassword为必填字段`



### (POST) /api/v1/auth/logout

> 用于登出面板

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |

---

返回结构：

```json
{
  "code": 403,
  "msg": "未登录"
}
```

| 字段 | 值        | 介绍     |
| ---- | --------- | -------- |
| code | enum[int] | 状态码   |
| msg  | string    | 附带消息 |



`code` 字段：

| 枚举值 | 含义         |
| ------ | ------------ |
| 0      | 您已成功退出 |
| 403    | 未登陆       |

## 站点信息

### (GET) /api/v1/guest/kv/site_info

> 获取面板的站点信息

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |

---

返回结构：

```json
{
  "code": 0,
  "data": "{\"title\":\"<title>\",\"allow_looking_glass\":<bool>,\"register_policy\":<int>,\"register_captcha_policy\":<int>,\"diagnose_hide_ip\":<int>}",
  "msg": ""
}
```

| 字段 | 值          | 介绍                                          |
| ---- | ----------- | --------------------------------------------- |
| code | enum[int]   | 状态码                                        |
| data | json string | 一段转义 json 字符串，包括了面板的 <站点信息> |
| msg  | string      | 附带消息                                      |



`code` 字段：

| 枚举值 | 含义     |
| ------ | -------- |
| 0      | 请求正常 |

> 不知道为什么，我测试的这个端点只有 0 这一个返回值
>
> 而且 Authorization 字段不存在时依然可以成功获取面板信息



### (GET) /api/v1/user/kv/site_notice

> 获取面板的公告信息

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |

---

返回结构：

```json
{
  "code": 0,
  "data": "<notice>",
  "msg": ""
}
```

| 字段 | 值        | 介绍                       |
| ---- | --------- | -------------------------- |
| code | enum[int] | 状态码                     |
| data | string    | 一段字符串，包括了面板公告 |
| msg  | string    | 附带消息                   |



`code` 字段：

| 枚举值 | 含义     |
| ------ | -------- |
| 0      | 请求正常 |
| 403    | 未登录   |



### (GET) /api/v1/system/info

> 获取面板的后端信息

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |

---

返回结构：

```json
{
  "code": 0,
  "data": {
    "license_expire": 1758376800,
    "time": 1743537226,
    "version": "20250307"
  },
  "msg": ""
}
```

| 字段 | 值        | 介绍                       |
| ---- | --------- | -------------------------- |
| code | enum[int] | 状态码                     |
| data | string    | 一段字符串，包括了面板公告 |
| msg  | string    | 附带消息                   |



`code` 字段：

| 枚举值 | 含义     |
| ------ | -------- |
| 0      | 请求正常 |
| 403    | 未登录   |



`data` 字段：

| 字段           | 值     | 介绍           |
| -------------- | ------ | -------------- |
| license_expire | int    | 到期日期时间戳 |
| time           | int    | 当前日期时间戳 |
| version        | string | 程序版本       |



### (GET) /api/v1/user/info

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |

---

返回结构：

```
{
  "code": 0,
  "data": {
    "admin": false,
    "aff_balance": "0",
    "allow_device": false,
    "auto_renew": false,
    "balance": "0",
    "banned": false,
    "connection_limit": 0,
    "expire": 253392451749,
    "group_id": 2,
    "group_name": "v1-relay",
    "id": 25,
    "invite_code": "",
    "invite_config": "",
    "inviter": 0,
    "ip_limit": 0,
    "max_rules": 20,
    "plan_id": 14,
    "plan_name": "Custom-rikki",
    "renew_price": "220",
    "speed_limit": 62500000,
    "telegram_id": 0,
    "telegram_notify": "",
    "traffic_enable": 3221225472000,
    "traffic_used": 465235343904,
    "username": "Rikki"
  },
  "msg": ""
}
```





## 规则控制

### (GET) /api/v1/user/devicegroup

> 获取设备组信息
>
> 创建/更新规则时，`device_group_in` 需要依靠设备组 id 这个字段来确定

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |

---

返回结构：

```json
{
  "code": 0,
  "data": [
    {
      "id": 1,
      "name": "一个设备名称",
      "type": "DeviceGroupType_Inbound",
      "ratio": "1",
      "traffic_used": 6548665240591,
      "connect_host": "11.45.1.4",
      "port_range": "30000-50000",
      "allowed_out": "2",
      "config": "{\"udp_smart_bind\":true,\"direct\":true}",
      "display_num": 1
    }
    ...
  ],
  "msg": ""
}
```



### (GET) /api/v1/user/forward

> 用于确定该账户下的规则列表

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |



参数：

| 字段 | 值   | 介绍                    |
| ---- | ---- | ----------------------- |
| page | int  | 指定的页数（从 0 开始） |
| size | int  | 每页的数量              |

> size 不填的时候默认输出全部规则



---

返回结构：（page=1，size=1）

```
{
  "code": 0,
  "data": [
    {
      "id": 343,
      "name": "一个规则名称",
      "uid": 25,
      "listen_port": 34769,
      "device_group_in": 1,
      "device_group_out": 0,
      "config": "{\"dest\":[\"1.1.1.1:23\"]}",
      "status": "ForwardRuleStatus_Normal",
      "display_updated_at": "2025-04-02 04:57:36 CST"
    }
  ],
  "count": 6
}
```



### (PUT) /api/v1/user/forward

> 用于创建新的转发

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |



请求体：

| 字段             | 值          | 介绍                                      | 必要性 |
| ---------------- | ----------- | ----------------------------------------- | ------ |
| config           | json string | 一段转义的json字符串，表示目标列表        | ✅      |
| device_group_in  | int         | 通过 `devicegroup` 接口获取的入口 id 信息 | ✅      |
| name             | string      | 指定改转发规则的名称                      | ✅      |
| device_group_out | int \| null | 出口 id 信息                              |        |
| listen_port      | int \| ""   | 指定入口侧的端口                          |        |

> `config` 的例子 `{\"dest\":[\"11.1.1.1:22\"]}` 

---

返回结构：

```
{"code":0,"msg":"创建成功"}
```

`code` 字段：

| 枚举值 | 含义                           |
| ------ | ------------------------------ |
| 0      | 创建成功                       |
| 400    | 此地址格式有误: {ip}           |
| 500    | 端口 {port} 已被使用，请换一个 |



### (POST) /api/v1/user/forward/batch_create

> 用于创建新的转发

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |



请求体：

| 字段             | 值                  | 介绍                                      | 必要性 |
| ---------------- | ------------------- | ----------------------------------------- | ------ |
| content          | string              | 一段用\n和#隔开的列表，表示目标规则       |        |
| device_group_in  | int                 | 通过 `devicegroup` 接口获取的入口 id 信息 | ✅      |
| device_group_out | int \| null         | 出口 id 信息                              | ✅      |
| config           | json string \| null | json 配置的规则表                         |        |

> `config` 的例子 `{\"dest\":[\"11.1.1.1:22\"]}` 



请求结构：

```json
{
	"content": "规则1#0#192.168.1.100#80\n规则1#0#192.168.1.100#80\n规则1#0#192.168.1.100#80",
	"device_group_in": 1,
	"device_group_out": null,
	"config": null
  
}
```

---

返回结构：

```
{"code":0,"msg":"导入了3条规则\n"}
```

`code` 字段：

| 枚举值 | 含义                           |
| ------ | ------------------------------ |
| 0      | 创建成功                       |
| 400    | 入口组不正确                   |
| 500    | 端口 {port} 已被使用，请换一个 |



### (POST) /api/v1/user/forward/{id}

> 用于更新指定 id 的规则

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |



请求体（application/json）：

```json
{
    "id": 346,
    "name": "希望更改的名字",
    "uid": 25,
    "listen_port": 42123,
    "device_group_in": 1,
    "device_group_out": null,
    "config": "{\"dest\":[\"11.1.1.1:22\"]}",
    "status": "ForwardRuleStatus_Normal",
    "display_updated_at": "2025-04-02 05:21:53 CST",
    "display_name": "原来的显示名字 (#346)",
    "display_traffic": "0.00 GiB"
}
```



---

返回结构：

```
{
  "code": 0,
  "msg": "修改成功"
}
```

`code` 字段：

| 枚举值 | 含义                                                         |
| ------ | ------------------------------------------------------------ |
| 0      | 修改成功                                                     |
| 400    | json: cannot unmarshal string into Go value of type dbmodel.ForwardRule |



### (POST) /api/v1/user/forward/reset_traffic

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |



请求体（application/json）：

```json
{"ids":[363,349]}
```



参数说明

| 字段 | 值            | 介绍                   |
| ---- | ------------- | ---------------------- |
| ids  | list[num,...] | 需要被重置流量的规则id |



返回结构：

```
{"code":0,"msg":"清空了2条已用流量"}
```

`code` 字段：

| code 枚举值 | msg 枚举值                                                   |
| ----------- | ------------------------------------------------------------ |
| 0           | 清空了2条已用流量（如果已经清空过，会提示：清空了0条已用流量） |
| 400         | EOF                                                          |



### (DELETE) /api/v1/user/forward

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |



请求体（application/json）：

```json
{"ids":[445]}
```



参数说明

| 字段 | 值            | 介绍                   |
| ---- | ------------- | ---------------------- |
| ids  | list[num,...] | 需要被重置流量的规则id |



返回结构：

```
{"code":0,"msg":"删除了1条规则"}
```

`code` 字段：

| code 枚举值 | msg 枚举值                                                   |
| ----------- | ------------------------------------------------------------ |
| 0           | 删除了n（根据msg返回确定）条规则（如果已经删除，会提示：删除了0条规则） |
| 400         | EOF                                                          |



## 规则控制

### (POST) /api/v1/user/forward/search_rules

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |

请求体：

```json
{"gid":0,"gid_in":1,"gid_out":0,"name":"5Rules::6::","dest":"","listen_port":0}
```

参数说明：

| 字段     | 值   | 介绍 |
| -------- | ---- | ---- |
| gid      | int  |   （不知道干什么的，不写好像也可以用）   |
| gid_in   | int  |   入口组id   |
| gid_out  | int  |   出口组id   |
| name     | int  |   名称（模糊搜索）   |
| dest     | int  |   目标   |
| listen_port | int  |   端口   |


返回结构：

```json
{
	"code": 0,
	"data": [{
		"id": 486,
		"name": "HKFD::1223221312::1",
		"uid": 73,
		"listen_port": 45713,
		"device_group_in": 1,
		"device_group_out": 0,
		"config": "{\"dest\":[\"1.1.1.1:80\"],\"speed_limit\":6250000}",
		"status": "ForwardRuleStatus_Normal"
	}],
	"msg": ""
}
```



### (POST) /api/v1/user/forward/batch_update

请求头：

| 字段          | 值     | 介绍                           |
| ------------- | ------ | ------------------------------ |
| Authorization | string | 从 `/login` 接口获取的账户凭据 |

请求体：

```json
[{"ids":[580,488],"column":"paused","value":true}]
```

参数说明：

| 字段   | 值     | 介绍                        |
| ------ | ------ | --------------------------- |
| ids    | int    | id列表                      |
| column | string | 功能项（暂停规则是 paused） |
| value  | int    | 设置该功能项的数值          |


返回结构：

```json
{"code":0,"msg":""}
```

