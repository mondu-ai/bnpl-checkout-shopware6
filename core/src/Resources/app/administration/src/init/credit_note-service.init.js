import CreditNoteService from '../services/credit_note.service'

Shopware.Service().register('creditNoteApiService', () => {
  const initContainer = Shopware.Application.getContainer('init');
  return new CreditNoteService(
    initContainer.httpClient, Shopware.Service('loginService')
  );
});
