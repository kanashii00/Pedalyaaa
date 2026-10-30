import http from 'k6/http';
import { check, sleep } from 'k6';

const baseUrl = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const apiKey = __ENV.DEVICE_API_KEY;
const bicycleId = Number(__ENV.BICYCLE_ID || 6);

if (!apiKey) {
  throw new Error('Set DEVICE_API_KEY through the environment.');
}

export const options = {
  vus: Number(__ENV.K6_VUS || 1),
  duration: __ENV.K6_DURATION || '3m',
  thresholds: {
    http_req_failed: ['rate<0.01'],
  },
};

export default function () {
  const payload = JSON.stringify({
    bicycle_id: bicycleId,
    device_id: `performance-simulated-device-${__VU}`,
    battery: 60,
    speed: 0,
    uptime: __ITER * 5,
    firmware: 'performance-test-simulator',
  });

  const response = http.post(`${baseUrl}/api/iot/heartbeat`, payload, {
    headers: {
      'Content-Type': 'application/json',
      'X-API-Key': apiKey,
    },
    tags: { flow: 'iot_heartbeat' },
  });

  check(response, {
    'heartbeat returns HTTP 200': (r) => r.status === 200,
  });

  // Five seconds keeps the test controlled while producing enough samples
  // for p95; it is not presented as a physical ESP32 test.
  sleep(5);
}
