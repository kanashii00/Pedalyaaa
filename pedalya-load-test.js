
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  vus: 10,
  duration: '3m',
};

export default function () {
  const response = http.get('http://127.0.0.1:8000/login');

  check(response, {
    'login page returns HTTP 200': (r) => r.status === 200,
  });

  sleep(1);
}