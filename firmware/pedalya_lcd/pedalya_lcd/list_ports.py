import serial.tools.list_ports
ports = list(serial.tools.list_ports.comports())
if not ports:
    print('NO COM PORTS FOUND')
else:
    for p in sorted(ports, key=lambda x: x.device):
        vid = hex(p.vid) if p.vid else "N/A"
        pid = hex(p.pid) if p.pid else "N/A"
        print("{:<8} vid={} pid={} desc={} mfr={}".format(
            p.device, vid, pid, p.description, p.manufacturer))

