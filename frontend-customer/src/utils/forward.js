/**
 * 从 subscription / proxy_ip 中提取对客展示用的连接信息
 * 如果存在活跃转发，优先用转发地址；否则用原始 IP:端口
 */
export function resolveConnection(subOrIp) {
  // subOrIp 既可能是 subscription（有 proxy_ip + forward_rule）
  // 也可能是 proxy_ip（有 active_subscription.forward_rule）
  const forward = subOrIp?.forward_rule
    || subOrIp?.active_subscription?.forward_rule
    || subOrIp?.activeSubscription?.forwardRule
    || null

  const proxyIp = subOrIp?.proxy_ip || subOrIp  // proxyIp 自身或嵌套

  const hasForward = forward && forward.status === 'active' && forward.listen_port
  if (hasForward) {
    const dg = forward.device_group
    const fp = forward.forward_plan || forward.forwardPlan
    const host = fp?.display_host || dg?.custom_connect_host || dg?.original_connect_host || proxyIp?.ip_address
    return {
      is_forwarded: true,
      host,
      port: forward.listen_port,
      username: proxyIp?.auth_username,
      password: proxyIp?.auth_password,
      speed_limit_mbps: forward.speed_limit_mbps,
      device_group_name: dg?.name,
      socks5: [host, forward.listen_port, proxyIp?.auth_username, proxyIp?.auth_password]
        .filter(Boolean).join(':'),
      original: {
        ip: proxyIp?.ip_address,
        port: proxyIp?.port,
      },
    }
  }

  return {
    is_forwarded: false,
    host: proxyIp?.ip_address,
    port: proxyIp?.port,
    username: proxyIp?.auth_username,
    password: proxyIp?.auth_password,
    socks5: [proxyIp?.ip_address, proxyIp?.port, proxyIp?.auth_username, proxyIp?.auth_password]
      .filter(Boolean).join(':'),
  }
}
