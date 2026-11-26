#-----------------------Сброс MAC Адресов---------------------------------

/interface ethernet reset-mac-address ether1
/interface ethernet reset-mac-address ether2
/interface ethernet reset-mac-address ether3
/interface ethernet reset-mac-address ether4
/interface ethernet reset-mac-address ether5

#-----------------------Конфиг---------------------------------

/interface ethernet
set [ find default-name=ether1 ] comment=inetmain
set [ find default-name=ether2 ] comment=inetreserve
set [ find default-name=ether3 ] comment=lan
set [ find default-name=ether4 ] comment=custom
set [ find default-name=ether5 ] comment=video




#{show:HAS_WAN_IP1}
/interface ipip
add local-address={WAN_IP_NOMASK1} name=ipip-office remote-address=ipip_salons.nikamed.ru
#{/if}

/interface wireguard
add listen-port=13231 mtu=1420 name=wg-office private-key="{WG_PRIVATE}"

/interface list
add name=WAN

/ip dhcp-server option
add code=138 name=unifi-controller value="'172.18.151.29'"

/ip pool
add name=dhcp_pool_lan ranges={POOL}

/ip dhcp-server
add add-arp=yes address-pool=dhcp_pool_lan interface=ether3 name=dhcp_lan

/routing id
add disabled=no name=id-main select-dynamic-id=only-loopback select-from-vrf=main

/routing ospf instance
add disabled=no in-filter-chain=ospf-in name=ospf-instance-31 originate-default=never router-id={ROUTER_ID1}
add disabled=no in-filter-chain=ospf-in name=ospf-instance-32 originate-default=never router-id={ROUTER_ID2}

/routing ospf area
add area-id=0.0.0.31 disabled=no instance=ospf-instance-31 name=ospf-area-31
add area-id=0.0.0.32 disabled=no instance=ospf-instance-32 name=ospf-area-32

/interface wireguard peers
add allowed-address=0.0.0.0/0 endpoint-address=wg-salons.nikamed.ru endpoint-port=13231 interface=wg-office name={NAME} persistent-keepalive=25s public-key="HctHjCNJcOqyRscFCFauFH6m9c8Yanydm7kCX/jYUh0="

/ip firewall connection tracking
set udp-timeout=10s

/ip neighbor discovery-settings
set discover-interface-list=!dynamic

/ip settings
set rp-filter=loose

/ipv6 settings
set disable-ipv6=yes forward=no max-neighbor-entries=8192

/interface list member
add interface=ether1 list=WAN
add interface=ether2 list=WAN

/ip address
#{show:HAS_WAN_IP1}
add address={WAN_IP1} interface=ether1
#{/if}

#{show:HAS_WAN_IP2}
add address={WAN_IP2} interface=ether2
#{/if}

add address={LAN_IP} interface=ether3
add address={VIDEO_IP} interface=ether5
#{show:HAS_WAN_IP1}
add address={IPIP_ADDRESS} interface=ipip-office
#{/if}
add address={WG_IP}/20 interface=wg-office
add address={LO_IP} interface=lo network={LO_IP}

/ip dhcp-server network
add address={LAN_NETWORK} dhcp-option=unifi-controller dns-server={LAN_IP_NOMASK} gateway={LAN_IP_NOMASK} ntp-server={LAN_IP_NOMASK}

#{show:DHCP_CLIENT1}
/ip dhcp-client
add default-route-distance=10 default-route-tables=main interface=ether1 name=client1 script=":if (\$bound=1) do\
    ={\r\
    \n    :local routeId [/ip route find where comment=\"gw1\"];\r\
    \n    \r\
    \n    :if ([:len \$routeId] = 0) do={\r\
    \n        /ip route add dst-address=0.0.0.0/0 gateway=\$\"gateway-address\" comment=\"gw1\";\r\
    \n    } else={\r\
    \n        /ip route set \$routeId gateway=\$\"gateway-address\";\r\
    \n    }\r\
    \n}\r\
    \n\r\
    \n:if (\$bound=0) do={\r\
    \n    /ip route remove [find where comment=\"gw1\"];\r\
    \n}" use-peer-dns=no
#{/if}


#{show:DHCP_CLIENT2}
/ip dhcp-client
add default-route-distance=10 default-route-tables=main interface=ether2 name=client1 script=":if (\$bound=1) do\
    ={\r\
    \n    :local routeId [/ip route find where comment=\"gw2\"];\r\
    \n    \r\
    \n    :if ([:len \$routeId] = 0) do={\r\
    \n        /ip route add dst-address=0.0.0.0/0 gateway=\$\"gateway-address\" comment=\"gw2\";\r\
    \n    } else={\r\
    \n        /ip route set \$routeId gateway=\$\"gateway-address\";\r\
    \n    }\r\
    \n}\r\
    \n\r\
    \n:if (\$bound=0) do={\r\
    \n    /ip route remove [find where comment=\"gw2\"];\r\
    \n}" use-peer-dns=no
#{/if}

/ip dns
set allow-remote-requests=yes cache-max-ttl=5m cache-size=3096KiB query-total-timeout=7s servers={DNS1}

/ip dns static
add address=172.18.151.29 name=unifi type=A

/ip firewall address-list
add address=185.222.154.64/28 comment=DL list=ACL
add address=185.84.245.96/28 comment=CO list=ACL
add address=213.208.165.240/28 comment=CO list=ACL
add address=78.24.24.140/30 comment=SNR list=ACL
add address=0.0.0.0/8 list=BOGON
add address=10.0.0.0/8 list=BOGON
add address=100.64.0.0/10 list=BOGON
add address=127.0.0.0/8 list=BOGON
add address=169.254.0.0/16 list=BOGON
add address=172.16.0.0/12 list=BOGON
add address=192.0.0.0/24 list=BOGON
add address=192.0.2.0/24 list=BOGON
add address=192.168.0.0/16 list=BOGON
add address=198.18.0.0/15 list=BOGON
add address=198.51.100.0/24 list=BOGON
add address=203.0.113.0/24 list=BOGON
add address=224.0.0.0/4 list=BOGON
add address=240.0.0.0/4 list=BOGON

/ip firewall filter
add action=accept chain=input comment="Permit established, related connections" connection-state=established,related
add action=drop chain=input comment="Deny invalid connections" connection-state=invalid
add action=accept chain=input comment="Permit icmp" protocol=icmp
add action=accept chain=input comment="WG" dst-port=13231 protocol=udp
add action=accept chain=input in-interface-list=WAN src-address-list=ACL
add action=drop chain=input comment=" Deny new connections" in-interface-list=WAN

/ip firewall nat
add action=masquerade chain=srcnat dst-address-list=!BOGON out-interface=ether1 src-address={LAN_NETWORK}
add action=masquerade chain=srcnat dst-address-list=!BOGON out-interface=ether2 src-address={LAN_NETWORK}

/ip firewall service-port
set sip disabled=yes sip-timeout=5s

/ip ipsec profile
set [ find default=yes ] dpd-interval=2m dpd-maximum-failures=5

/ip route
add check-gateway=ping comment=gw2 disabled=no distance=5 dst-address=0.0.0.0/0 gateway={GATEWAY2} routing-table=main scope=30 target-scope=10
#{show:HAS_WAN_IP1}
add check-gateway=ping comment=gw1 disabled=no distance=1 dst-address=0.0.0.0/0 gateway={GATEWAY1} routing-table=main scope=30 target-scope=10
add check-gateway=ping disabled=no distance=1 dst-address=77.88.8.1/32 gateway={GATEWAY1} routing-table=main scope=30 target-scope=10
#{/if}

/routing bfd configuration
add disabled=no interfaces=all min-rx=200ms min-tx=200ms multiplier=5

/routing filter rule
add chain=ospf-in disabled=no rule="if (dst in 172.16.0.0/12) {set pref-src {LAN_IP_NOMASK}; accept;}"
add chain=ospf-in disabled=no rule="if (dst in 10.210.0.0/16) {set pref-src {LAN_IP_NOMASK}; accept;}"
add chain=ospf-in disabled=no rule="if (dst in 10.220.0.0/16) {set pref-src {LAN_IP_NOMASK}; accept;}"
add chain=ospf-in disabled=no rule="if (dst == 10.255.255.1/32) {set pref-src {LAN_IP_NOMASK}; accept;}"

/routing ospf interface-template
#{show:HAS_WAN_IP1}
add area=ospf-area-31 disabled=no interfaces=ipip-office type=ptp use-bfd=yes
add area=ospf-area-31 disabled=no interfaces=ether3 passive
add area=ospf-area-31 disabled=no interfaces=ether5 passive
#{/if}
add area=ospf-area-32 cost=100 disabled=no interfaces=wg-office type=nbma use-bfd=no
add area=ospf-area-32 cost=100 disabled=no interfaces=ether3 passive
add area=ospf-area-32 cost=100 disabled=no interfaces=ether5 passive

/routing ospf static-neighbor
add address=10.252.127.254%wg-office area=ospf-area-32 disabled=no

/radius
add address=10.210.25.2 secret=ilZKNByQj1V%xAetKlAUvR4N6&FtpGKnGxNRBlCpD9k9VQX4br6XqNNZ!rkMjy2q service=login src-address={LAN_IP_NOMASK}

/system clock
set time-zone-autodetect=no time-zone-name=Europe/Moscow

/system identity
set name={NAME}

/system ntp client
set enabled=yes

/system ntp server
set enabled=yes

/system ntp client servers
add address=10.255.255.1

/system logging action
set 3 remote=172.18.151.174 src-address={LAN_IP_NOMASK} syslog-facility=local0

/system logging
add action=remote topics=critical
add action=remote topics=error
add action=remote topics=account

/user aaa
set use-radius=yes

/system note
set show-at-cli-login=no
set show-at-login=no 

/tool netwatch
add disabled=no dns-server=172.16.0.8 down-script="" host=zabbix7.nikamed.ru http-codes="" interval=1m name=DNS-1 \
    record-type=A src-address={LAN_IP_NOMASK} startup-delay=20s test-script=":local backupAvailable false\r\
    \n:local gw1Id [/ip route find comment=\"gw1\"]\r\
    \n:local gw1Distance 10\r\
    \n\r\
    \n:if ([:len \$gw1Id] > 0) do={\r\
    \n    :set gw1Distance [/ip route get \$gw1Id distance]\r\
    \n}\r\
    \n\r\
    \n:if ([/tool netwatch get [find name=\"backup-gw-check\"] status] = \"up\") do={\r\
    \n    :set backupAvailable true\r\
    \n}\r\
    \n\r\
    \n:local dnsUp false\r\
    \n:if ([/tool netwatch get [find name=\"DNS-1\"] status] = \"up\") do={\r\
    \n    :set dnsUp true\r\
    \n}\r\
    \n\r\
    \n:local wantDNS [:toarray \"\"]\r\
    \n\r\
    \n:if (\$dnsUp = true) do={\r\
    \n    :set wantDNS {\"172.16.0.8\";\"172.16.0.9\"}\r\
    \n}\r\
    \n\r\
    \n:if (\$gw1Distance = 10 and \$backupAvailable = true and \$dnsUp = false) do={\r\
    \n    :set wantDNS {\"{DNS2}\"}\r\
    \n}\r\
    \n\r\
    \n:if ([:len \$wantDNS] = 0) do={\r\
    \n    :set wantDNS {\"{DNS1}\"}\r\
    \n}\r\
    \n\r\
    \n:local curDNS [/ip dns get servers]\r\
    \n\r\
    \n:if ([:tostr \$wantDNS] != [:tostr \$curDNS]) do={\r\
    \n    /ip dns set servers=\$wantDNS\r\
    \n    /ip dns cache flush\r\
    \n    :log warning (\"DNS changed to \" . [:tostr \$wantDNS])\r\
    \n}" timeout=3s type=dns up-script=""

/tool netwatch
add disabled=no down-script="/log warning \"Backup gateway is down\"" host=\
    {GATEWAY2} http-codes="" interval=1m name=backup-gw-check \
    startup-delay=30s test-script="" timeout=1s type=icmp up-script=\
    "/log warning \"Backup gateway is up\""


#{show:HAS_WAN_IP1}
/tool netwatch
add disabled=no down-script="" host=77.88.8.1 http-codes="" interval=1m name=primary-external-check src-address={WAN_IP_NOMASK1} startup-delay=30s \
    test-script=":local mode [/system note get note]\r\
    \n:local lossPercent \$\"loss-percent\"\r\
    \n:local ipipRunning [/interface ipip get [find name=ipip-office] running]\r\
    \n:local backupAvailable false\r\
    \n:local gw1Id [/ip route find comment=\"gw1\"]\r\
    \n:local gw1Distance 0\r\
    \n:local wgDisabled \"\"\r\
    \n:local peerId [/interface wireguard peers find]\r\
    \n\r\
    \n:if ([:len \$gw1Id] > 0) do={\r\
    \n:set gw1Distance [/ip route get \$gw1Id distance]\r\
    \n}\r\
    \n\r\
    \n:if ([:len \$peerId] > 0) do={\r\
    \n:set wgDisabled [/interface wireguard peers get [:pick \$peerId 0] disabled]\r\
    \n}\r\
    \n\r\
    \n:log info (\"DBG loss=\$lossPercent gw1d=\$gw1Distance wgDisabled=\$wgDisabled backup=\$backupAvailable\")\r\
    \n\r\
    \n:if (\$mode = \"auto\" or \$mode = \"\") do={\r\
    \n:if ([/tool netwatch get [find name=\"backup-gw-check\"] status] = \"up\") do={\r\
    \n:set backupAvailable true\r\
    \n}\r\
    \n:if ([/interface lte find name=lte1] != \"\") do={\r\
    \n:if ([/interface lte get lte1 running] = true) do={\r\
    \n:set backupAvailable true\r\
    \n}\r\
    \n}\r\
    \n\r\
    \n:if (\$lossPercent > 400  and \$backupAvailable = true and \$gw1Distance != 10) do={\r\
    \n/log warning \"IPIP down, backup available - switching to WG\"\r\
    \n:if ([:len \$peerId] > 0) do={\r\
    \n    /interface wireguard peers set [:pick \$peerId 0] disabled=no\r\
    \n}\r\
    \n/interface ipip set ipip-office disabled=yes\r\
    \n/ip route set [find comment=\"gw1\"] distance=10\r\
    \n/ip route set [find comment=\"gw2\"] distance=1\r\
    \n/log info \"Switched to WG via backup\"\r\
    \n}\r\
    \n\r\
    \n\r\
    \n:if (\$lossPercent < 100 and \$gw1Distance = 1 and \$wgDisabled = false) do={\r\
    \n/log warning \"WG is up while primary is OK - normalizing to IPIP\"\r\
    \n:if ([:len \$peerId] > 0) do={\r\
    \n    /interface wireguard peers set [:pick \$peerId 0] disabled=yes\r\
    \n}\r\
    \n/interface ipip set ipip-office disabled=no\r\
    \n/ip route set [find comment=\"gw1\"] distance=1\r\
    \n/ip route set [find comment=\"gw2\"] distance=10\r\
    \n}\r\
    \n\r\
    \n\r\
    \n:if (\$lossPercent < 100 and \$gw1Distance = 10) do={\r\
    \n/log info \"Primary recovered - restoring IPIP\"\r\
    \n:if ([:len \$peerId] > 0) do={\r\
    \n    /interface wireguard peers set [:pick \$peerId 0] disabled=yes\r\
    \n}\r\
    \n/interface ipip set ipip-office disabled=no\r\
    \n/ip route set [find comment=\"gw1\"] distance=1\r\
    \n/ip route set [find comment=\"gw2\"] distance=10\r\
    \n}\r\
    \n\r\
    \n\r\
    \n}" thr-loss-percent=40% timeout=1s type=icmp up-script=""


/system script
add dont-require-permissions=yes name=force-primary owner=adminov policy=\
    read,write,policy,test,sniff,sensitive source="/system note set note=\"primary\"\r\
    \n/ip route set [find comment=\"gw1\"] distance=1\r\
    \n/ip route set [find comment=\"gw2\"] distance=10\r\
    \n/interface ipip set ipip-office disabled=no\r\
    \n/interface wireguard peers set 0 disabled=yes\r\
    \n/ip dns cache flush\r\
    \n/system identity set name=\"{NAME} - MAIN\"\r\
    \n/log warning \"To MAIN link\""

/system script
add dont-require-permissions=yes name=set-auto owner=adminov policy=\
    read,write,policy,test,sniff,sensitive source="/system note set note=\"auto\"\r\
    \n/system identity set name=\"{NAME} - AUTO\"\r\
    \n/log warning \"Failover mode set to AUTO\""
#{/if}


/system script
add dont-require-permissions=yes name=force-backup owner=adminov policy=\
    read,write,policy,test,sniff,sensitive source="/system note set note=\"backup\"\r\
    \n/ip route set [find comment=\"gw1\"] distance=10\r\
    \n/ip route set [find comment=\"gw2\"] distance=1\r\
    \n/interface ipip set ipip-office disabled=yes\r\
    \n/interface wireguard peers set 0 disabled=no\r\
    \n/ip dns cache flush\r\
    \n/system identity set name=\"{NAME} - SEC\"\r\
    \n/log warning \"To REZERV link\""   


#{show:HAS_WAN_IP1}
/system scheduler
add name=set-auto on-event=set-auto policy=read,write,policy,test,sniff,sensitive start-time=startup
#{/if}

