export default class MonduConfigService extends Shopware.Classes.ApiService {
  constructor(httpClient, loginService, apiEndpoint = 'mondus') {
    super(httpClient, loginService, apiEndpoint);
  }

  testApiCredentials(apiCredentials) {
    return this.httpClient
      .post(`/mondu/config/test`, { apiCredentials: apiCredentials },
        {
          headers: this.getBasicHeaders()
        })
      .then(response => response.data)
  }
}