import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const baseUrl = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const email = __ENV.LOGIN_EMAIL;
const password = __ENV.LOGIN_PASSWORD;

if (!email || !password) {
  throw new Error('Set LOGIN_EMAIL and LOGIN_PASSWORD through the environment.');
}

export const options = {
  vus: Number(__ENV.K6_VUS || 10),
  duration: __ENV.K6_DURATION || '3m',
  thresholds: {
    login_failed: ['rate<0.01'],
  },
};

const loginDuration = new Trend('login_request_duration', true);
const loginFailed = new Rate('login_failed');
let loggedIn = false;

export default function () {
  if (!loggedIn) {
    const jar = http.cookieJar();
    const form = http.get(`${baseUrl}/login`, { jar, tags: { flow: 'login_form' } });
    const token = (form.body.match(/name="_token"\s+value="([^"]+)"/) || [])[1];
    const response = http.post(`${baseUrl}/login`, {
      _token: token || '',
      email,
      password,
    }, {
      jar,
      redirects: 0,
      tags: { flow: 'login_submit' },
    });

    const success = check(response, {
      'login redirects after valid credentials': (r) => r.status === 302,
    });
    loginDuration.add(response.timings.duration);
    loginFailed.add(!success);
    loggedIn = success;
  }

  // Keep the authenticated session alive without repeatedly hitting the
  // throttled login route. The login metrics above cover the actual POST.
  sleep(20);
}
