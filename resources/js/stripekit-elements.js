import { loadStripe } from 'https://cdn.jsdelivr.net/npm/@stripe/stripe-js@3/+esm';

let cachedStripePromise = null;

export function loadStripeKitJs(publishableKey) {
  if (!cachedStripePromise) {
    cachedStripePromise = loadStripe(publishableKey);
  }
  return cachedStripePromise;
}

export async function createElements(config) {
  const stripe = await loadStripeKitJs(config.publishableKey);
  if (!stripe) {
    throw new Error('Stripe.js failed to load. Check your publishable key and network connection.');
  }

  const elements = stripe.elements({
    clientSecret: config.clientSecret,
    appearance: config.appearance,
    locale: config.locale,
  });

  return { stripe, elements };
}

export class PaymentElementController {
  stripe = null;
  elements = null;
  paymentElement = null;

  static async create(config) {
    const controller = new PaymentElementController();
    const { stripe, elements } = await createElements(config);
    controller.stripe = stripe;
    controller.elements = elements;
    return controller;
  }

  mount(options) {
    if (!this.elements) {
      throw new Error('PaymentElementController is not initialized. Call PaymentElementController.create() first.');
    }

    this.paymentElement = this.elements.create('payment', {
      layout: options.layout ?? 'tabs',
      fields: {
        billingDetails: options.fields?.billingDetails ?? 'auto',
      },
    });

    this.paymentElement.mount(options.containerSelector);
    return this.paymentElement;
  }

  unmount() {
    this.paymentElement?.unmount();
    this.paymentElement = null;
  }

  async confirmPayment(options) {
    if (!this.stripe || !this.elements) {
      throw new Error('PaymentElementController is not initialized.');
    }

    const confirmParams = {
      return_url: options.returnUrl,
      receipt_email: options.receiptEmail,
    };

    const result =
      options.redirect === 'always'
        ? await this.stripe.confirmPayment({ elements: this.elements, confirmParams, redirect: 'always' })
        : await this.stripe.confirmPayment({ elements: this.elements, confirmParams, redirect: 'if_required' });

    if (result.error) {
      return { success: false, error: result.error.message ?? 'Payment confirmation failed.' };
    }

    const paymentIntent = result.paymentIntent;

    return {
      success: paymentIntent.status === 'succeeded' || paymentIntent.status === 'processing',
      paymentIntentId: paymentIntent.id,
      status: paymentIntent.status,
    };
  }

  async confirmSetup(options) {
    if (!this.stripe || !this.elements) {
      throw new Error('PaymentElementController is not initialized.');
    }

    const confirmParams = { return_url: options.returnUrl };

    const result =
      options.redirect === 'always'
        ? await this.stripe.confirmSetup({ elements: this.elements, confirmParams, redirect: 'always' })
        : await this.stripe.confirmSetup({ elements: this.elements, confirmParams, redirect: 'if_required' });

    if (result.error) {
      return { success: false, error: result.error.message ?? 'Setup confirmation failed.' };
    }

    const setupIntent = result.setupIntent;

    return {
      success: setupIntent.status === 'succeeded',
      paymentIntentId: setupIntent.id,
      status: setupIntent.status,
    };
  }
}
