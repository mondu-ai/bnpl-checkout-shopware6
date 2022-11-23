export default class MonduConfigService extends Shopware.Classes.ApiService {
  constructor(httpClient, loginService, apiEndpoint = 'mondus') {
    super(httpClient, loginService, apiEndpoint);
  }

  testApiCredentials(apiCredentials, sandboxMode) {
    return this.httpClient
      .post(`/mondu/config/test`, { apiCredentials: apiCredentials, sandboxMode: sandboxMode },
        {
          headers: this.getBasicHeaders()
        })
      .then(response => response.data)
  }
}